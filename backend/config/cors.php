<?php

// CORS restrito: o caso padrão é mesma origem via nginx (sem CORS). Origens extras só por env.
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // CORS_ALLOWED_ORIGINS="https://app.exemplo.com,https://outro.exemplo.com" (default: vazio = nenhuma)
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Request-Id', 'X-Requested-With'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,
];
