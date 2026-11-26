<?php

namespace App\Notifications;

final class ProviderHttpEndpoint
{
    public function options(string $host, int $port): array
    {
        $host = strtolower(rtrim($host, '.'));
        if (! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host)
            || ! in_array($host, config('homer.provider_http_hosts'), true)) {
            throw new DeliveryFailure('provider_host_not_allowed');
        }
        if ((! function_exists('curl_exec') && ! function_exists('curl_multi_exec'))
            || ! function_exists('socket_addrinfo_lookup') || ! function_exists('socket_addrinfo_explain')) {
            throw new DeliveryFailure('provider_safe_transport_unavailable');
        }

        $records = @socket_addrinfo_lookup($host.'.', null, ['ai_socktype' => SOCK_STREAM]);
        if (! $records) {
            throw new DeliveryFailure('provider_dns_unavailable', retry: true);
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = socket_addrinfo_explain($record)['ai_addr'];
            $ip = $address['sin_addr'] ?? $address['sin6_addr'] ?? '';
            if (! $this->isPublicAddress($ip)) {
                throw new DeliveryFailure('provider_address_not_allowed');
            }
            $addresses[] = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        }

        // Use only the addresses checked for this connection. Keep the hostname for TLS.
        return [
            'proxy' => '',
            'curl' => [
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', array_unique($addresses))],
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
            ],
        ];
    }

    private function isPublicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        $binary = inet_pton($ip);
        if (strlen($binary) === 4) {
            // Exclude multicast and the Azure host endpoint.
            return ord($binary[0]) < 224 && $ip !== '168.63.129.16';
        }

        // Exclude IPv4-mapped, NAT64, Teredo and 6to4 addresses.
        return (ord($binary[0]) & 0xe0) === 0x20
            && ! str_starts_with($binary, "\x20\x02")
            && ! str_starts_with($binary, "\x20\x01\x00\x00");
    }
}
