<?php

namespace App\Services;

/**
 * RFC 6238 time-based one-time passwords (TOTP), implemented directly on
 * PHP's hash_hmac — no external dependency (MASTER_RULES §27.2: justify every
 * dependency; the primitive is two dozen lines and fully testable).
 *
 *  - 30-second period, HMAC-SHA1, 6 digits (the Google Authenticator /
 *    Authy-compatible default).
 *  - Secrets are 160-bit random values, base32-encoded (RFC 4648).
 *  - Verification tolerates ±1 step for clock skew.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public const WINDOW = 1;

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function otpauthUrl(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * The 6-digit code for a given time (defaults to now).
     */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);
        $binary = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binary, self::base32Decode($secret), true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Constant-time comparison against the codes of the current step and the
     * ±WINDOW neighbours (clock skew tolerance).
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = self::WINDOW): bool
    {
        $timestamp ??= time();

        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::code($secret, $timestamp + ($i * self::PERIOD));
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 4648 base32 (no padding, uppercase) — the format authenticator
     * apps expect for TOTP secrets.
     */
    public static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return rtrim($encoded, 'A');
    }

    public static function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $clean = strtoupper(str_replace(['=', ' ', '-'], '', $base32));

        $bits = '';
        foreach (str_split($clean) as $char) {
            $index = strpos($alphabet, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Invalid base32 character: '.$char);
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
