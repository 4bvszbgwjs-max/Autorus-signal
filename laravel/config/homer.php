<?php

return [
    'api_token' => env('HOMER_API_TOKEN'),
    'admin_password_hash' => env('HOMER_ADMIN_PASSWORD_HASH'),
    'rabbitmq_url' => env('RMQ_BROKER_URL'),
    'rabbitmq_exchange' => env('RMQ_CREATE_MESSAGE_EXCHANGE'),
    'purge_expired' => (bool) env('HOMER_PURGE_EXPIRED', false),
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
