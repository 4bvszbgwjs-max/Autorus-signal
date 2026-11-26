<?php

use App\Notifications\DeliveryFailure;
use App\Notifications\PrepareSendings;
use App\Notifications\ProviderHttpEndpoint;
use App\Notifications\Providers;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, "Use APP_ENV=testing with a migrated test PostgreSQL database.\n");
    exit(1);
}

$token = Str::random(32);
config(['homer.api_token' => $token, 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);

$request = function (string $method, string $path, array $payload, int $expected) use ($kernel, $token): array {
    $request = Request::create('/api/v1/'.$path, $method, server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("$method $path expected HTTP $expected, got ".$response->getStatusCode());
    }

    return $response->getContent() === '' ? [] : json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
};

$passed = false;
DB::beginTransaction();
try {
    $channel = 'review-'.Str::lower(Str::random(10));
    $providerId = (string) Str::uuid();
    $createdChannel = $request('POST', 'channel', [
        'code' => $channel, 'name' => 'Regression test', 'sender' => 'smsIdgtl',
        'config' => ['token' => 'synthetic-channel-credential'],
    ], 201)['data'];
    $storedConfig = fn () => DB::table('channels')->where('code', $channel)->value('config');
    $ciphertext = $storedConfig();
    if (! array_key_exists('config', $createdChannel) || $createdChannel['config'] !== []
        || str_contains($ciphertext, 'synthetic-channel-credential')) {
        throw new RuntimeException('Channel credentials were not masked and encrypted.');
    }
    $request('PATCH', 'channel/'.$channel, ['config' => (object) []], 200);
    if ($storedConfig() !== $ciphertext) {
        throw new RuntimeException('Saving a masked channel cleared its stored configuration.');
    }
    $request('PATCH', 'channel/'.$channel, ['config' => null], 200);
    if (Crypt::decryptString($storedConfig()) !== '{}') {
        throw new RuntimeException('An explicit clear did not remove the channel configuration.');
    }

    $phone = Str::random(16);
    $recipient = $request('POST', 'recipient', [
        'phone' => $phone, 'applicationFirebaseToken' => 'synthetic-push-token',
    ], 201)['data'];
    $recipientPath = 'recipient/'.$recipient['id'];
    $storedToken = fn () => DB::table('recipients')->where('id', $recipient['id'])->value('applicationFirebaseToken');
    if (($recipient['applicationFirebaseToken'] ?? null) !== '') {
        throw new RuntimeException('The response did not mask the Firebase token.');
    }
    $request('PATCH', $recipientPath, $recipient, 200);
    $request('PATCH', 'recipient/bulk/upsert', [['phone' => $phone, 'applicationFirebaseToken' => '']], 200);
    if ($storedToken() !== 'synthetic-push-token') {
        throw new RuntimeException('Saving a masked recipient cleared its stored token.');
    }
    $request('PATCH', $recipientPath, ['applicationFirebaseToken' => null], 200);
    if ($storedToken() !== null) {
        throw new RuntimeException('An explicit clear did not remove the Firebase token.');
    }
    $message = $request('POST', 'message', ['recipientId' => 'synthetic-recipient'], 200)['data'][0];
    $request('POST', 'message', ['status' => 8], 400);
    $request('POST', 'message', [['status' => null], ['subject' => 'Synthetic batch']], 400);
    $page = $request('GET', 'message?limit=1', [], 200);
    if ($message['status'] !== 0 || $page['meta']['limit'] !== 1 || count($page['data']) !== 1) {
        throw new RuntimeException('New notifications or explicit page size violate the API contract.');
    }
    $sending = $request('POST', 'sending', [
        'channel' => $channel,
        'message' => $message['id'],
        'uuid' => $providerId,
        'content' => 'Synthetic message',
        'destination' => 'synthetic-recipient',
    ], 201)['data'];
    $row = DB::table('sendings')->where('id', $sending['id']);
    (clone $row)->update(['status' => 2, 'claimedAt' => now()]);

    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'delivered'], 409);
    $request('DELETE', 'channel/'.$channel, [], 409);
    $request('PATCH', 'message/'.$message['id'], ['isDeleted' => true], 409);
    if ((int) (clone $row)->value('status') !== 2 || (clone $row)->value('claimedAt') === null) {
        throw new RuntimeException('A provider callback changed a sending held by the worker.');
    }

    (clone $row)->update(['status' => 3, 'claimedAt' => null]);
    foreach ([0, 1, 2] as $queueStatus) {
        $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => $queueStatus], 400);
    }
    $request('PATCH', 'sending/status', [
        ['uuid' => $providerId, 'status' => 'delivered'],
        ['uuid' => $providerId, 'status' => 1],
    ], 400);
    if ((int) (clone $row)->value('status') !== 3) {
        throw new RuntimeException('A rejected callback changed a sent record.');
    }
    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'delivered'], 200);
    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'sent'], 200);
    if ((int) (clone $row)->value('status') !== 7 || (int) DB::table('messages')->where('id', $message['id'])->value('status') !== 7) {
        throw new RuntimeException('The late callback regressed the sending or its parent.');
    }

    $otherChannel = $channel.'-other';
    $request('POST', 'channel', ['code' => $otherChannel, 'name' => 'Other channel', 'sender' => 'smsIdgtl'], 201);
    $other = $request('POST', 'sending', [
        'channel' => $otherChannel, 'uuid' => $providerId, 'content' => 'Synthetic collision', 'status' => 3,
    ], 201)['data'];
    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'delivered'], 409);
    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'channel' => $otherChannel, 'status' => 'delivered'], 200);
    if ((int) DB::table('sendings')->where('id', $other['id'])->value('status') !== 7) {
        throw new RuntimeException('A channel-scoped callback did not update its sending.');
    }

    foreach ([4, 5, 6] as $failure) {
        $parent = $request('POST', 'message', ['isDeleted' => true], 200)['data'][0];
        $request('PATCH', 'message/'.$parent['id'], ['status' => 1, 'isDeleted' => false], 200);
        $child = ['message' => $parent['id'], 'content' => 'Multi-channel notification', 'status' => 3];
        $request('POST', 'sending', $child + ['channel' => $channel], 201);
        $failureUuid = (string) Str::uuid();
        $request('POST', 'sending', $child + ['channel' => $otherChannel, 'uuid' => $failureUuid], 201);
        $request('PATCH', 'sending/status', ['uuid' => $failureUuid, 'status' => $failure], 200);
        if ((int) DB::table('messages')->where('id', $parent['id'])->value('status') !== $failure) {
            throw new RuntimeException('A successful channel masked a failed or expired channel.');
        }
    }

    $scenario = $request('POST', 'scenario', [
        'messageType' => 2,
        'byDefault' => true,
        'channelsFirstOf' => [[
            'channel' => $otherChannel, 'isActive' => true, 'fieldsConditions' => ['equalTrue' => ['isSubscribe']],
        ]],
    ], 201)['data'];
    $direct = ['recipientId' => $phone, 'channels' => [$channel], 'mustBeSend' => '2099-01-01T00:00:00Z'];
    $explicit = $request('POST', 'message', $direct + ['scenario_id' => $scenario['uuid']], 200)['data'][0];
    $byType = $request('POST', 'message', $direct + ['type' => 2], 200)['data'][0];
    $noScenario = $request('POST', 'message', $direct + ['type' => 0], 200)['data'][0];
    $disabled = $request('POST', 'scenario', ['messageType' => 0, 'isDeleted' => true], 201)['data'];
    $explicitDisabled = $request('POST', 'message', $direct + ['scenario_id' => $disabled['uuid']], 200)['data'][0];
    $removed = $request('POST', 'scenario', ['messageType' => 0], 201)['data'];
    $explicitRemoved = $request('POST', 'message', $direct + ['scenario_id' => $removed['uuid']], 200)['data'][0];
    $request('DELETE', 'scenario/'.$removed['id'], [], 204);
    $app->make(PrepareSendings::class)->batch(100);
    foreach ([$explicit['id'], $byType['id'], $explicitDisabled['id'], $explicitRemoved['id']] as $blocked) {
        $prepared = $request('GET', 'message/'.$blocked, [], 200)['data'];
        if ((int) $prepared['status'] !== 4 || $prepared['channels'] !== [] || DB::table('sendings')->where('message', $blocked)->exists()) {
            throw new RuntimeException('The worker used a direct channel despite a selected scenario.');
        }
    }
    if (DB::table('messages')->where('id', $explicitDisabled['id'])->value('scenario_id') !== $disabled['uuid']) {
        throw new RuntimeException('The worker lost the explicitly selected disabled scenario.');
    }
    if (DB::table('messages')->where('id', $explicitRemoved['id'])->value('scenario_id') !== $removed['uuid']) {
        throw new RuntimeException('Physical deletion removed the explicit scenario UUID from a pending notification.');
    }
    $request('PATCH', 'message/'.$explicitRemoved['id'], ['scenario_id' => $removed['uuid'], 'subject' => 'Still editable'], 200);
    $request('PATCH', 'message/'.$noScenario['id'], ['scenario_id' => $removed['uuid']], 400);
    if (! DB::table('sendings')->where('message', $noScenario['id'])->where('channel', $channel)->exists()) {
        throw new RuntimeException('The worker ignored direct channels on a message without a scenario.');
    }

    $request('POST', 'sending', [
        'channel' => $channel, 'message' => $message['id'],
        'destination' => 'synthetic-recipient', 'content' => 'Pending cancellation',
    ], 201);
    $request('PATCH', 'message/'.$message['id'], ['isDeleted' => true], 200);
    if ($app->make(App\Notifications\DeliverSendings::class)->batch(1) !== 0) {
        throw new RuntimeException('The worker claimed a child of a soft-deleted message.');
    }

    $queued = ['channel' => $channel, 'destination' => 'synthetic-recipient', 'content' => 'Queued without a claim', 'status' => 2];
    $stale = $request('POST', 'sending', $queued, 201)['data'];
    $recent = $request('POST', 'sending', $queued, 201)['data'];
    DB::table('sendings')->where('id', $stale['id'])->update(['updatedAt' => now()->subMinutes(10)]);
    $app->make(App\Notifications\DeliverSendings::class)->batch(10);
    $settled = DB::table('sendings')->where('id', $stale['id'])->first();
    if ((int) $settled->status !== 5 || $settled->deliveryError !== 'delivery_outcome_unknown' || (int) $settled->attempts !== 0
        || (int) DB::table('sendings')->where('id', $recent['id'])->value('status') !== 2) {
        throw new RuntimeException('The worker did not settle only the stale sending without repeating delivery.');
    }

    $sms = 'https://public.provider.invalid/sms';
    $push = 'https://public.provider.invalid/push';
    $retail = 'https://public.provider.invalid/retail';
    config(['homer.provider_http_hosts' => [
        'public.provider.invalid', 'private.provider.invalid', 'mixed.provider.invalid', 'metadata.provider.invalid',
        'ipv6.provider.invalid', 'multicast.provider.invalid',
    ]]);
    Http::preventStrayRequests();
    Http::fake([
        $sms => Http::response(['items' => [['messageUuid' => 'synthetic-sms']]]),
        $push => Http::response(['results' => [['message_id' => 'synthetic-push']]]),
        $retail.'/*' => Http::response([], 200),
    ]);
    $providers = $app->make(Providers::class);
    $sample = (object) ['destination' => 'test-address', 'subject' => 'Subject', 'content' => 'Synthetic body'];
    foreach (['smsIdgtl' => $sms, 'pushFirebase' => $push] as $sender => $url) {
        $providers->send($sender, ['url' => $url], $sample, null);
        $providers->send($sender, ['url' => $url, 'method' => 'PUT'], $sample, null);
        if (Http::recorded(fn (ProviderRequest $call) => $call->url() === $url && $call->method() === 'POST'
            && str_contains($call->body(), 'Synthetic body'))->count() !== 1
            || Http::recorded(fn (ProviderRequest $call) => $call->url() === $url && $call->method() === 'PUT')->count() !== 1) {
            throw new RuntimeException('A provider did not send JSON with POST by default or ignored an explicit method.');
        }
    }
    $mixedCaseId = $providers->send('smsIdgtl', ['url' => 'https://PUBLIC.Provider.INVALID./sms'], $sample, null);
    if ($mixedCaseId !== 'synthetic-sms'
        || Http::recorded(fn (ProviderRequest $call) => $call->url() === $sms && $call->method() === 'POST')->count() !== 2) {
        throw new RuntimeException('A mixed-case provider URL did not use the canonical host.');
    }
    $providers->send('emailRetailRocket', ['url' => $retail.'?mode=synthetic', 'apikey' => 'synthetic-key'], $sample,
        (object) ['metadata' => '{"campaignId":42,"synthetic":true}']);
    $retailCalls = Http::recorded(function (ProviderRequest $call) use ($retail): bool {
        parse_str((string) parse_url($call->url(), PHP_URL_QUERY), $query);

        return strtok($call->url(), '?') === $retail.'/42' && $call->method() === 'GET'
            && $query == ['mode' => 'synthetic', 'apikey' => 'synthetic-key', 'email' => 'test-address']
            && json_decode($call->body(), true) === ['campaignId' => 42, 'synthetic' => true];
    });
    if ($retailCalls->count() !== 1) {
        throw new RuntimeException('Retail Rocket did not receive the expected path, query and JSON body.');
    }

    $options = $app->make(ProviderHttpEndpoint::class)->options('public.provider.invalid', 443);
    $mixedCaseOptions = $app->make(ProviderHttpEndpoint::class)->options('PUBLIC.Provider.INVALID.', 443);
    if ($options['curl'][CURLOPT_RESOLVE] !== ['public.provider.invalid:443:93.184.216.34']
        || $mixedCaseOptions !== $options
        || ! $options['curl'][CURLOPT_FRESH_CONNECT] || ! $options['curl'][CURLOPT_FORBID_REUSE]
        || $options['curl'][CURLOPT_PROXY] !== '') {
        throw new RuntimeException('Provider connection was not pinned to the validated address.');
    }
    foreach ([
        [['url' => 'http://127.0.0.1/'], 'provider_host_not_allowed'],
        [['url' => 'http://different.provider.invalid/'], 'provider_host_not_allowed'],
        [['url' => 'http://private.provider.invalid/'], 'provider_address_not_allowed'],
        [['url' => 'http://mixed.provider.invalid/'], 'provider_address_not_allowed'],
        [['url' => 'http://metadata.provider.invalid/'], 'provider_address_not_allowed'],
        [['url' => 'http://ipv6.provider.invalid/'], 'provider_address_not_allowed'],
        [['url' => 'http://multicast.provider.invalid/'], 'provider_address_not_allowed'],
        [['url' => $sms, 'headers' => ['hOsT' => 'different.provider.invalid']], 'invalid_http_configuration'],
    ] as [$config, $code]) {
        try {
            $providers->send('smsIdgtl', $config, $sample, null);
            throw new RuntimeException('An unsafe provider request reached the HTTP client.');
        } catch (DeliveryFailure $failure) {
            if ($failure->getMessage() !== $code) {
                throw $failure;
            }
        }
    }

    $subjects = [
        'expired-oldest' => now()->subDays(3),
        'expired-next' => now()->subDays(2),
        'expired-claimed' => now()->subDays(4),
        'still-current' => now()->addDay(),
    ];
    $purgeRows = [];
    foreach ($subjects as $subject => $expire) {
        $parent = $request('POST', 'message', ['subject' => $subject, 'expire' => $expire->toIso8601String()], 200)['data'][0];
        $child = $request('POST', 'sending', [
            'channel' => $channel, 'message' => $parent['id'], 'content' => 'Cleanup regression', 'status' => 1,
            'expire' => $expire->toIso8601String(),
        ], 201)['data'];
        $purgeRows[$subject] = ['message' => $parent['id'], 'sending' => $child['id']];
    }
    DB::table('sendings')->where('id', $purgeRows['expired-claimed']['sending'])->update(['status' => 2, 'claimedAt' => now()]);

    if (Artisan::call('homer:purge-expired', ['--dry-run' => true, '--limit' => 0]) !== 1
        || Artisan::call('homer:purge-expired', ['--dry-run' => true, '--limit' => 10]) !== 0
        || trim(Artisan::output()) !== 'Eligible messages in this batch: 2'
        || Artisan::call('homer:purge-expired', ['--dry-run' => true, '--limit' => 1]) !== 0
        || trim(Artisan::output()) !== 'Eligible messages in this batch: 1') {
        throw new RuntimeException('The cleanup preview ignored eligibility or the batch limit.');
    }
    foreach ($purgeRows as $ids) {
        foreach ($ids as $resource => $id) {
            $request('GET', $resource.'/'.$id, [], 200);
        }
    }

    if (Artisan::call('homer:purge-expired', ['--limit' => 1]) !== 0 || trim(Artisan::output()) !== 'Purged messages: 1') {
        throw new RuntimeException('Cleanup did not delete exactly one expired message.');
    }
    foreach ($purgeRows as $subject => $ids) {
        foreach ($ids as $resource => $id) {
            $request('GET', $resource.'/'.$id, [], $subject === 'expired-oldest' ? 404 : 200);
        }
    }
    DB::table('sendings')->where('id', $purgeRows['expired-claimed']['sending'])->update(['status' => 6, 'claimedAt' => null]);

    $request('DELETE', 'channel/'.$channel, [], 204);
    $request('GET', 'sending/'.$sending['id'], [], 404);
    $passed = true;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
} finally {
    DB::rollBack();
}

if (! $passed) {
    exit(1);
}

echo "Delivery callbacks, aggregate statuses, scenario rules, provider methods, cleanup and cascade checks passed.\n";
