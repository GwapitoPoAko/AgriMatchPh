<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Vite dev server + whatever FRONTEND_URL is set to in .env. Never "*" when
    // credentials are allowed — browsers reject it and it would leak cookies.
    'allowed_origins' => array_values(array_filter([
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5174',
        'http://localhost:4173', // vite preview
        'http://127.0.0.1:4173', // vite preview
        env('FRONTEND_URL'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Idempotency-Key', 'Authorization'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 600,

    // Sanctum SPA authentication rides on the session cookie + XSRF-TOKEN cookie.
    'supports_credentials' => true,
];
