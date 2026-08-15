<?php

/*
|--------------------------------------------------------------------------
| Swasthya platform configuration
|--------------------------------------------------------------------------
|
| All Swasthya-specific settings live here, driven by SWASTHYA_-prefixed
| environment variables (MASTER_RULES.md §28.2). This file is committed;
| values are placeholders/defaults — never secrets.
|
*/

return [

    'api' => [
        // Versioned API root (API_CONTRACTS.md §2).
        'prefix' => 'api/v1',

        // CORS allowlist — comma-separated origins; never '*' with credentials.
        // (SECURITY.md §24, MASTER_RULES.md §6.1)
        'cors_allowed_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SWASTHYA_CORS_ALLOWED_ORIGINS', ''))
        ))),
    ],

    'auth' => [
        // Access-token lifetime (minutes) — short-lived per SECURITY.md §5;
        // refresh tokens rotate and last 7 days by default.
        'access_token_ttl_minutes' => (int) env('SWASTHYA_ACCESS_TOKEN_TTL_MINUTES', 60),
        'refresh_token_ttl_days' => (int) env('SWASTHYA_REFRESH_TOKEN_TTL_DAYS', 7),
        // Failed-login lockout: after this many consecutive failures the
        // account is locked out for the window below (SECURITY.md §18).
        'login_failure_threshold' => (int) env('SWASTHYA_LOGIN_FAILURE_THRESHOLD', 5),
        'login_lockout_minutes' => (int) env('SWASTHYA_LOGIN_LOCKOUT_MINUTES', 15),

        // Phase 3 — Supabase-native access-token claims (see
        // App\Support\JwtClaims). HS256-signed JWTs whose payload carries the
        // five `app_*` claims the RLS layer reads from `request.jwt.claims`.
        // This models the token an edge-function signer mints in the native
        // architecture; the signature secret is server-side only and NEVER
        // reaches the browser or the frontend build.
        'jwt' => [
            // Signing secret. MUST be set in production via
            // SWASTHYA_AUTH_JWT_SECRET; when empty, JwtClaims derives a
            // stable key from APP_KEY (local/testing only — rotating APP_KEY
            // then fails every token closed, which is the safe direction).
            'secret' => (string) env('SWASTHYA_AUTH_JWT_SECRET', ''),
            'issuer' => (string) env('SWASTHYA_AUTH_JWT_ISSUER', 'swasthya'),
            'audience' => (string) env('SWASTHYA_AUTH_JWT_AUDIENCE', 'swasthya-api'),
            // Access-token lifetime (seconds) for issued claims tokens.
            'access_ttl_seconds' => (int) env('SWASTHYA_ACCESS_TOKEN_TTL_MINUTES', 60) * 60,
        ],
    ],

    'rate_limits' => [
        // Requests per minute, per IP. Per-account limits arrive with auth.
        // (API_CONTRACTS.md §15, SECURITY.md §17)
        'api' => (int) env('SWASTHYA_RATE_LIMIT_API', 300),   // default reads
        'auth' => (int) env('SWASTHYA_RATE_LIMIT_AUTH', 5),   // authentication endpoints
        'writes' => (int) env('SWASTHYA_RATE_LIMIT_WRITES', 60), // create/mutate endpoints
    ],

];
