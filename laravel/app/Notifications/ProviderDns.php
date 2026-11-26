<?php

namespace App\Notifications;

final class ProviderDns
{
    public function addresses(string $host, ?string $privateIp = null, bool $trustedBroker = false): array
    {
        $literalIp = $trustedBroker ? filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) : false;
        $service = $trustedBroker && preg_match('/\A[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host);
        if ($literalIp === false && ! $service
            && ! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host)) {
            throw new DeliveryFailure('provider_host_not_allowed');
        }
        if (! function_exists('socket_addrinfo_lookup') || ! function_exists('socket_addrinfo_explain')) {
            throw new DeliveryFailure('provider_safe_transport_unavailable');
        }

        $pin = $privateIp === null ? null : @inet_pton($privateIp);
        if ($pin !== null && ! $this->isPrivateAddress($pin)) {
            throw new DeliveryFailure('provider_address_not_allowed');
        }
        $addresses = [];
        foreach ($literalIp === false ? $this->resolve($host) : [$literalIp] as $ip) {
            $binary = @inet_pton($ip);
            $allowed = $pin !== null
                ? $binary === $pin
                : ($this->isPublicAddress($ip) || ($trustedBroker && $this->isPrivateAddress($binary)));
            if (! $allowed) {
                throw new DeliveryFailure('provider_address_not_allowed');
            }
            $addresses[] = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        }

        return array_unique($addresses);
    }

    private function resolve(string $host): array
    {
        $records = @socket_addrinfo_lookup($host.'.', null, ['ai_socktype' => SOCK_STREAM]);
        if (! $records) {
            throw new DeliveryFailure('provider_dns_unavailable', retry: true);
        }

        return array_map(static function ($record): string {
            $address = socket_addrinfo_explain($record)['ai_addr'];

            return $address['sin_addr'] ?? $address['sin6_addr'] ?? '';
        }, $records);
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

    private function isPrivateAddress(string|false $binary): bool
    {
        if ($binary === false) {
            return false;
        }
        if (strlen($binary) === 16) {
            // Exclude AWS IPv6 metadata and other reserved endpoints in its service prefix.
            return (ord($binary[0]) & 0xfe) === 0xfc && ! str_starts_with($binary, "\xfd\x00\x0e\xc2");
        }

        return ord($binary[0]) === 10
            || (ord($binary[0]) === 172 && (ord($binary[1]) & 0xf0) === 16)
            || str_starts_with($binary, "\xc0\xa8");
    }
}
