<?php

namespace App\Notifications;

final class ProviderAmqpEndpoint
{
    public function __construct(private readonly ProviderDns $dns) {}

    public function addresses(string $scheme, string $host, int $port): array
    {
        $endpoint = $scheme.'://'.$host.':'.$port;
        $configured = config('homer.provider_amqp_brokers');
        if (array_key_exists($endpoint, $configured)) {
            return $this->dns->addresses($host, $configured[$endpoint]);
        }

        $defaultUrl = config('homer.rabbitmq_url');
        $default = is_string($defaultUrl) ? parse_url($defaultUrl) : false;
        $defaultScheme = strtolower($default['scheme'] ?? '');
        if ($scheme !== $defaultScheme
            || $host !== strtolower(rtrim($default['host'] ?? '', '.'))
            || $port !== ($default['port'] ?? ($defaultScheme === 'amqps' ? 5671 : 5672))) {
            throw new DeliveryFailure('amqp_broker_not_allowed');
        }

        return $this->dns->addresses($host, trustedBroker: true);
    }
}
