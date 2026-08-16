<?php

namespace App\Support;

/**
 * HMAC-SHA256 webhook signature verification (INTEROPERABILITY.md §11,
 * SECURITY.md §35): an inbound webhook must present a valid signature over
 * the raw body (HMAC-SHA256, hex) computed with the partner's webhook
 * secret, plus a timestamp nonce within a bounded replay window. An
 * unsigned, malformed, stale, or mismatched signature is rejected — never
 * processed. Constant-time comparison throughout.
 */
final class WebhookSignature
{
    public const MAX_AGE_SECONDS = 300;

    /**
     * @param  array<string, string>  $headers  (lower-cased keys)
     */
    public static function verify(
        string $payload,
        array $headers,
        string $secret,
        int $maxAgeSeconds = self::MAX_AGE_SECONDS,
    ): bool {
        if ($secret === '') {
            return false;
        }

        $signature = $headers['x-swasthya-signature'] ?? '';
        $timestamp = $headers['x-swasthya-timestamp'] ?? '';

        if ($signature === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $age = time() - (int) $timestamp;
        if ($age < -60 || $age > $maxAgeSeconds) {
            return false; // replayed or future-dated
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return hash_equals($expected, strtolower($signature));
    }

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
