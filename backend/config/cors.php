<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Strict allowlist only (SECURITY.md §24, MASTER_RULES.md §6.1):
    | allowed origins come from SWASTHYA_CORS_ALLOWED_ORIGINS (comma-
    | separated). Never '*' with credentials. CORS is a browser policy, never
    | an authorization control — the API does not trust Origin.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => config('swasthya.api.cors_allowed_origins'),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'If-Match',
        'X-Correlation-Id',
        'X-Requested-With',
        'X-Swasthya-Facility',
    ],

    'exposed_headers' => [
        'Deprecation',
        'ETag',
        'Idempotency-Replayed',
        'Location',
        'Retry-After',
        'Sunset',
        'X-Audit-Event-Id',
        'X-Correlation-Id',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'X-Request-Id',
    ],

    'max_age' => 86400,

    // False until the refresh-token cookie flow lands (then the SPA origin
    // is allowlisted and credentials are enabled for it only).
    'supports_credentials' => false,

];
