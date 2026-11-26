<?php

namespace App\Notifications;

final class ProviderHttpEndpoint
{
    public function __construct(private readonly ProviderDns $dns) {}

    public function options(string $host, int $port): array
    {
        $host = strtolower(rtrim($host, '.'));
        if (! in_array($host, config('homer.provider_http_hosts'), true)) {
            throw new DeliveryFailure('provider_host_not_allowed');
        }
        if (! function_exists('curl_exec') && ! function_exists('curl_multi_exec')) {
            throw new DeliveryFailure('provider_safe_transport_unavailable');
        }

        $addresses = $this->dns->addresses($host);

        // Use only the addresses checked for this connection. Keep the hostname for TLS.
        return [
            'proxy' => '',
            'curl' => [
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $addresses)],
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
            ],
        ];
    }
}
