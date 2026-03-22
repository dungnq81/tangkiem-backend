<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | CORS origins are dynamically loaded from the tk_api_domains table
    | via the CorsServiceProvider. No manual .env configuration needed —
    | just register domains in __admin/api-domains.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Populated dynamically by CorsServiceProvider from tk_api_domains
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Accept',
        'Authorization',
        'X-Requested-With',
        'X-Public-Key',
        'X-Secret-Key',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    'max_age' => 86400, // 24 hours — browser caches preflight response

    'supports_credentials' => true, // Required for Sanctum SPA auth

];
