<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | PostgreSQL is the only database Swasthya uses (MASTER_RULES.md §5.1,
    | ARCHITECTURE.md §7). The default is pgsql; other drivers are not
    | configured because the constitution prohibits them in production.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | The pgsql connection is the platform's single data plane. RLS tenancy
    | (DATABASE.md §1.5), UUID keys, and timestamptz are enforced at the
    | schema layer — see database/migrations.
    |
    */

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'swasthya'),
            'username' => env('DB_USERNAME', 'swasthya'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            // Timestamps are stored and returned in UTC; the session
            // timezone is pinned so serialization is deterministic
            // (DATABASE.md §0.3). Rendering happens client-side per facility
            // timezone (API_CONTRACTS.md §20).
            'timezone' => env('DB_TIMEZONE', 'UTC'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        // The RLS verification connection: connects as the least-privilege
        // APPLICATION role (swasthya_app — no BYPASSRLS, no ownership) so the
        // database-level tenancy tests prove the policies actually filter,
        // something a superuser connection can never demonstrate (TENANCY.md
        // V2 §10, SECURITY.md §14). Only used by the RLS test suite.
        'pgsql_rls' => [
            'driver' => 'pgsql',
            'url' => env('RLS_DB_URL'),
            'host' => env('RLS_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('RLS_DB_PORT', env('DB_PORT', '5432')),
            'database' => env('RLS_DB_DATABASE', env('DB_DATABASE', 'swasthya')),
            'username' => env('RLS_DB_USERNAME', 'swasthya_app'),
            'password' => env('RLS_DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'timezone' => env('DB_TIMEZONE', 'UTC'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | Migrations are the only way the schema changes (MASTER_RULES.md §30):
    | forward-only, backward-compatible, fresh-database-clean.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis owns cache, queues, rate limiting, sessions, and realtime
    | (MASTER_RULES.md §3.1). Production uses Redis; local defaults use
    | stateless drivers. Rate limiting is Redis-backed in production so
    | limits survive instance scaling (SECURITY.md §17).
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'swasthya')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
