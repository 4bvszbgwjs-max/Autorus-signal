<?php

namespace App\Notifications;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

final class Providers
{
    public function __construct(
        private readonly RabbitMq $rabbit,
        private readonly ProviderHttpEndpoint $endpoint,
        private readonly ProviderDns $dns,
    ) {}

    public function send(string $sender, array $config, object $sending, ?object $message): string
    {
        $fallback = (string) Str::uuid();
        $id = match ($sender) {
            'notices' => $this->notices($config, $sending, $message, $fallback),
            'builtinEmail' => $this->smtp($config, $sending, $fallback),
            'telegram' => $this->telegram($config, $sending),
            'smsIdgtl' => $this->sms($config, $sending, $fallback),
            'emailRetailRocket' => $this->retailRocket($config, $sending, $message, $fallback),
            'pushFirebase' => $this->firebase($config, $sending, $fallback),
            default => throw new DeliveryFailure('unsupported_sender'),
        };

        if ((! is_int($id) && ! is_string($id)) || $id === '' || strlen((string) $id) > 200 || str_contains((string) $id, "\0")) {
            throw new DeliveryFailure('invalid_provider_id', uncertain: true);
        }

        return (string) $id;
    }

    private function notices(array $config, object $sending, ?object $message, string $fallback): string
    {
        $id = $message->uuid ?? $fallback;
        $payload = [[
            'uuid' => $id,
            'subject' => $sending->subject,
            'content' => $sending->content,
            'recipientId' => $message->recipientId ?? $sending->destination,
            ...($message ? [
                'type' => $message->type,
                'tags' => json_decode($message->tags, flags: JSON_THROW_ON_ERROR),
                'metadata' => json_decode($message->metadata, flags: JSON_THROW_ON_ERROR),
            ] : []),
        ]];

        $url = $this->required($config, 'url');
        if (in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['amqp', 'amqps'], true)) {
            $this->rabbit->publish($url, $this->required($config, 'exchange'), $config['routingKey'] ?? $config['routing_key'] ?? '', $payload, $id);
        } else {
            $this->http($config, $url, $payload, 'post');
        }

        return $id;
    }

    private function smtp(array $config, object $sending, string $id): string
    {
        $implicitTls = filter_var($config['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $host = strtolower(rtrim($this->required($config, 'server'), '.'));
        $port = filter_var($config['port'] ?? ($implicitTls ? 465 : 587), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new DeliveryFailure('invalid_smtp_port');
        }

        $relays = config('homer.provider_smtp_relays');
        $relay = $host.':'.$port;
        if (! array_key_exists($relay, $relays)) {
            throw new DeliveryFailure('smtp_relay_not_allowed');
        }
        $addresses = $this->dns->addresses($host, $relays[$relay]);
        $transport = new EsmtpTransport(reset($addresses), $port, $implicitTls);
        $transport->setRequireTls(true);
        // Each notification uses a fresh SMTP session and explicitly completes the handshake before send().
        $transport->setPingThreshold(PHP_INT_MAX);
        $transport->getStream()->setTimeout(10)->setStreamOptions(['ssl' => [
            'peer_name' => $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ]]);
        if (! empty($config['user'])) {
            $transport->setUsername($config['user']);
            $transport->setPassword($this->required($config, 'password'));
        }

        $email = (new Email)->from($this->required($config, 'email'))->to($sending->destination)
            ->subject($sending->subject)->text($sending->content ?? '')->html($sending->content ?? '');
        $email->getHeaders()->addIdHeader('Message-ID', $id.'@homer.local');
        // Only try another checked address before any message data has been sent.
        foreach ($addresses as $index => $address) {
            $transport->getStream()->setHost($address);
            try {
                $transport->start();
                break;
            } catch (TransportExceptionInterface $failure) {
                if ($index === array_key_last($addresses)) {
                    throw $failure;
                }
            }
        }
        (new Mailer($transport))->send($email);

        return $id;
    }

    private function telegram(array $config, object $sending): mixed
    {
        $token = $this->required($config, 'token');
        if (! preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', $token)) {
            throw new DeliveryFailure('invalid_telegram_token');
        }
        $base = rtrim($config['apiBaseUrl'] ?? 'https://api.telegram.org', '/');
        $response = $this->http(['method' => 'post'], $base.'/bot'.$token.'/sendMessage', [
            'chat_id' => $sending->destination,
            'text' => $sending->content,
        ], 'post')->json();
        if (($response['ok'] ?? false) !== true) {
            throw new DeliveryFailure('telegram_result_unknown', uncertain: true);
        }

        return $response['result']['message_id'] ?? null;
    }

    private function sms(array $config, object $sending, string $fallback): mixed
    {
        $response = $this->http($config, $this->required($config, 'url'), [[
            'channelType' => 'SMS',
            'senderName' => $config['senderName'] ?? 'autorus.ru',
            'destination' => $sending->destination,
            'content' => $sending->content,
        ]], 'post')->json();
        if (! is_array($response)) {
            throw new DeliveryFailure('sms_result_unknown', uncertain: true);
        }

        return $response['items'][0]['messageUuid'] ?? $fallback;
    }

    private function retailRocket(array $config, object $sending, ?object $message, string $fallback): string
    {
        $metadata = $message ? json_decode($message->metadata, flags: JSON_THROW_ON_ERROR) : (object) [];
        $url = new Uri($this->required($config, 'url'));
        if (isset($metadata->campaignId) && (is_string($metadata->campaignId) || is_int($metadata->campaignId))) {
            $url = $url->withPath(rtrim($url->getPath(), '/').'/'.rawurlencode((string) $metadata->campaignId));
        }
        $apiKey = $config['apikey'] ?? $config['apiKeyValue'] ?? null;
        if (! is_string($apiKey) || $apiKey === '') {
            throw new DeliveryFailure('missing_provider_configuration');
        }
        $this->http($config, (string) $url, $metadata, 'get', ['apikey' => $apiKey, 'email' => $sending->destination]);

        return $fallback;
    }

    private function firebase(array $config, object $sending, string $fallback): mixed
    {
        if (isset($config['serviceAccount'])) {
            $account = $config['serviceAccount'];
            if (! is_array($account)) {
                throw new DeliveryFailure('invalid_firebase_credentials');
            }
            $token = $this->firebaseToken($account);
            $project = rawurlencode($this->required($account, 'project_id'));
            $response = $this->http(['headers' => ['Authorization' => 'Bearer '.$token]], "https://fcm.googleapis.com/v1/projects/$project/messages:send", [
                'message' => [
                    'token' => $sending->destination,
                    'notification' => ['title' => $sending->subject, 'body' => $sending->content ?? ''],
                    'data' => ['score' => '3x1'],
                    'android' => ['ttl' => '3600s', 'priority' => 'HIGH', 'notification' => ['click_action' => 'arus_notification']],
                ],
            ], 'post')->json();

            return $response['name'] ?? null;
        }

        // Legacy-format payload is for an explicitly configured compatible gateway.
        $response = $this->http($config, $this->required($config, 'url'), [
            'to' => $sending->destination,
            'priority' => 10,
            'time_to_live' => 3600,
            'data' => ['score' => '3x1', 'title' => $sending->subject, 'body' => $sending->content],
            'notification' => ['title' => $sending->subject, 'body' => $sending->content, 'sound' => 'default', 'click_action' => 'arus_notification'],
        ], 'post')->json();
        if (! is_array($response)) {
            throw new DeliveryFailure('firebase_result_unknown', uncertain: true);
        }
        if (($response['failure'] ?? 0) > 0 || isset($response['results'][0]['error'])) {
            throw new DeliveryFailure('firebase_rejected');
        }

        return $response['results'][0]['message_id'] ?? $fallback;
    }

    private function firebaseToken(array $account): string
    {
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $now = time();
        $url = 'https://oauth2.googleapis.com/token';
        $jwt = $encode('{"alg":"RS256","typ":"JWT"}').'.'.$encode(json_encode([
            'iss' => $this->required($account, 'client_email'),
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $url,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private($this->required($account, 'private_key'));
        if ($key === false || ! openssl_sign($jwt, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new DeliveryFailure('invalid_firebase_credentials');
        }
        try {
            $response = Http::asForm()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->withOptions($this->endpoint->options('oauth2.googleapis.com', 443))->post($url, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt.'.'.$encode($signature),
            ]);
        } catch (ConnectionException) {
            throw new DeliveryFailure('firebase_token_unavailable', retry: true);
        }
        if ($response->status() === 429 || $response->serverError()) {
            throw new DeliveryFailure('firebase_token_unavailable', retry: true);
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new DeliveryFailure('firebase_token_rejected');
        }

        return $response->json('access_token');
    }

    private function http(array $config, string $url, array|object $data, string $default, array $query = []): Response
    {
        $uri = new Uri($url);
        $method = strtoupper($config['method'] ?? $default);
        $host = rtrim($uri->getHost(), '.');
        $headers = $config['headers'] ?? [];
        if (! in_array($uri->getScheme(), ['http', 'https'], true) || $host === ''
            || ! is_array($headers) || array_key_exists('host', array_change_key_case($headers))
            || ! in_array($method, ['GET', 'POST', 'PATCH', 'PUT'], true)) {
            throw new DeliveryFailure('invalid_http_configuration');
        }

        $uri = $uri->withHost($host);
        $request = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withHeaders($headers);
        if ($uri->getUserInfo() !== '') {
            [$user, $password] = array_pad(explode(':', $uri->getUserInfo(), 2), 2, '');
            $request = $request->withBasicAuth(rawurldecode($user), rawurldecode($password));
            $uri = $uri->withUserInfo('');
        }
        $options = $this->endpoint->options($host, $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80));
        $options['json'] = $data;
        if ($query !== []) {
            parse_str($uri->getQuery(), $configuredQuery);
            $options['query'] = [...$configuredQuery, ...$query];
        }

        try {
            $response = $request->send($method, (string) $uri, $options);
        } catch (ConnectionException) {
            throw new DeliveryFailure('provider_outcome_unknown', uncertain: true);
        }

        if ($response->status() === 429) {
            throw new DeliveryFailure('provider_rate_limited', retry: true);
        }
        if (! $response->successful()) {
            throw new DeliveryFailure('provider_http_'.$response->status(), uncertain: $response->serverError());
        }

        return $response;
    }

    private function required(array $config, string $field): string
    {
        if (! is_string($config[$field] ?? null) || $config[$field] === '') {
            throw new DeliveryFailure('missing_provider_configuration');
        }

        return $config[$field];
    }
}
