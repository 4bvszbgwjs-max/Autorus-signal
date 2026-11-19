<?php

return [
    'api_token' => env('HOMER_API_TOKEN'),
    'admin_password_hash' => env('HOMER_ADMIN_PASSWORD_HASH'),
    'rabbitmq_url' => env('RMQ_BROKER_URL'),
    'rabbitmq_exchange' => env('RMQ_CREATE_MESSAGE_EXCHANGE'),
    'purge_expired' => (bool) env('HOMER_PURGE_EXPIRED', false),
];
