<?php

/*
|--------------------------------------------------------------------------
| Hashing Drivers
|--------------------------------------------------------------------------
|
| Passwords are hashed with argon2id by default (SECURITY.md §2,
| DATABASE.md §3.4 — `users.password_hash`); bcrypt remains available as a
| compliant fallback via HASH_DRIVER=bcrypt. Passwords are never stored
| plaintext, reversible, or in logs.
|
*/

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

];
