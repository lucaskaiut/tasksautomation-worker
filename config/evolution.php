<?php

return [
    'notifications' => [
        'enabled' => filter_var(env('NOTIFICATIONS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
    'email' => [
        'enabled' => filter_var(env('NOTIFICATIONS_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'to' => array_values(array_filter(array_map(
            static fn (string $email): string => trim($email),
            explode(',', (string) env('NOTIFICATIONS_EMAIL_TO', ''))
        ), static fn (string $email): bool => $email !== '')),
    ],
    'whatsapp' => [
        'enabled' => filter_var(env('EVOLUTION_WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('EVOLUTION_API_BASE_URL', ''),
        'api_key' => env('EVOLUTION_API_KEY', ''),
        'instance_name' => env('EVOLUTION_INSTANCE_NAME', ''),
        'destination_number' => env('EVOLUTION_DESTINATION_NUMBER', ''),
        'timeout_seconds' => (int) env('EVOLUTION_API_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('EVOLUTION_API_CONNECT_TIMEOUT', 5),
    ],
];
