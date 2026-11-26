<?php

$parseEndpoints = static function (string $value): array {
    $endpoints = [];
    foreach (explode(',', $value) as $entry) {
        [$endpoint, $privateIp] = array_pad(explode('=', $entry, 2), 2, null);
        if (trim($endpoint) !== '') {
            $endpoints[strtolower(trim($endpoint))] = $privateIp === null ? null : trim($privateIp);
        }
    }

    return $endpoints;
};

return [
    'api_token' => env('HOMER_API_TOKEN'),
    'callback_api_token' => env('HOMER_CALLBACK_API_TOKEN'),
    'admin_password_hash' => env('HOMER_ADMIN_PASSWORD_HASH'),
    'rabbitmq_url' => env('RMQ_BROKER_URL'),
    'rabbitmq_exchange' => env('RMQ_CREATE_MESSAGE_EXCHANGE'),
    'purge_expired' => (bool) env('HOMER_PURGE_EXPIRED', false),
    'provider_smtp_relays' => $parseEndpoints((string) env('HOMER_PROVIDER_SMTP_RELAYS', '')),
    'provider_amqp_brokers' => $parseEndpoints((string) env('HOMER_PROVIDER_AMQP_BROKERS', '')),
    'provider_http_hosts' => [
        'api.telegram.org',
        'fcm.googleapis.com',
        'oauth2.googleapis.com',
        ...array_filter(array_map(
            fn (string $host): string => strtolower(rtrim(trim($host), '.')),
            explode(',', (string) env('HOMER_PROVIDER_HTTP_HOSTS', '')),
        )),
    ],
];
