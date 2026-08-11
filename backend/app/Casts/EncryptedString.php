<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * App-layer encryption at rest (SECURITY.md §12): the attribute holds
 * ciphertext in the database and plaintext in application memory.
 *
 * Used for fields that must be readable by the application but must never
 * rest as plaintext — e.g. staff license numbers (DATABASE.md §3.10). The
 * cipher is AES-256-GCM via the application key; nothing of these values is
 * ever written to logs or audit payloads.
 */
class EncryptedString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) && $value !== '' ? Crypt::decryptString($value) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Crypt::encryptString((string) $value);
    }
}
