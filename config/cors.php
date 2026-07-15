<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Restrict to your dashboard/SPA origins in production.
    'allowed_origins' => explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('APP_URL', 'http://localhost'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
