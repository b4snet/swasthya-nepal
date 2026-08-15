<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Support\Str;

/**
 * Phase 3 — Supabase-native access-token claims (config/swasthya.php 'auth.jwt').
 *
 * A minimal, dependency-free HS256 JWT codec. It models the token an
 * edge-function signer mints in the Supabase-native architecture: a JWT whose
 * payload carries the five `app_*` claims (app_user_id, app_tenant_id,
 * app_facility_id, app_branch_id, app_is_platform) that the RLS layer reads
 * from `request.jwt.claims` (Phase 2 helpers, 2026_08_13_100200). Supabase's
 * pooler surfaces the verified JWT payload through exactly that GUC, so the
 * token this class issues and the claims it exposes are byte-for-byte what
 * the RLS policies consume.
 *
 * Security properties (SECURITY.md §5, MASTER_RULES.md §7):
 *  - HS256 with a server-side secret; the secret is never exposed to the
 *    browser, the frontend build, or any VITE_* variable.
 *  - `alg` is pinned to HS256 — `alg:none`, asymmetric algorithms, and any
 *    algorithm-confusion header are rejected.
 *  - Signature comparison uses hash_equals (constant time).
 *  - iat/nbf/exp are validated with a small clock-skew leeway; issuer and
 *    audience are pinned.
 *  - The claim values are set by the server (AuthClaims::fromContext) from
 *    the authenticated principal's RESOLVED context — never from client
 *    input (TENANCY.md §7, API_CONTRACTS.md §5).
 *
 * In production the signing secret MUST be SWASTHYA_AUTH_JWT_SECRET. When it
 * is empty (local/testing), a stable key is derived from APP_KEY; rotating
 * APP_KEY then invalidates every token fail-closed, never silently.
 */
final class JwtClaims
{
    private const ALG = 'HS256';

    /** Clock-skew leeway accepted on iat/nbf/exp (seconds). */
    private const LEEWAY_SECONDS = 30;

    private const KEY_DERIVATION_CONTEXT = 'swasthya.auth.jwt'; // stable context for the APP_KEY fallback

    /**
     * Sign $claims into an HS256 JWT. Adds the standard iss/aud/iat/nbf/exp/
     * jti claims; the supplied claim values are preserved as-is (callers are
     * expected to pass AuthClaims::fromContext() output).
     *
     * @param  array<string, string>  $claims
     */
    public static function issue(array $claims, ?int $ttlSeconds = null): string
    {
        $ttlSeconds ??= (int) config('swasthya.auth.jwt.access_ttl_seconds');
        $now = time();

        $payload = $claims + [
            'iss' => (string) config('swasthya.auth.jwt.issuer'),
            'aud' => (string) config('swasthya.auth.jwt.audience'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => (string) Str::uuid(),
        ];

        return self::encode([
            'alg' => self::ALG,
            'typ' => 'JWT',
        ], $payload, self::key());
    }

    /**
     * Verify the token's structure, signature, expiry, issuer and audience,
     * then return the payload claims.
     *
     * @return array<string, mixed> the decoded payload (standard claims
     *                              included; filter with AuthClaims::normalize for RLS use)
     *
     * @throws ApiException INVALID_TOKEN (malformed, bad signature, wrong
     *                      alg, wrong issuer/audience) or TOKEN_EXPIRED
     */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw self::invalid();
        }

        [$headerRaw, $payloadRaw, $signatureRaw] = $parts;

        $header = json_decode(self::b64decode($headerRaw), true);
        $payload = json_decode(self::b64decode($payloadRaw), true);

        if (! is_array($header) || ! is_array($payload)) {
            throw self::invalid();
        }

        // Algorithm pinning: reject alg:none and any algorithm confusion.
        if (($header['alg'] ?? null) !== self::ALG) {
            throw self::invalid();
        }

        $expected = self::sign("{$headerRaw}.{$payloadRaw}", self::key());
        if (! hash_equals($expected, $signatureRaw)) {
            throw self::invalid();
        }

        $now = time();

        if (! isset($payload['exp']) || ! is_numeric($payload['exp']) || (int) $payload['exp'] < $now - self::LEEWAY_SECONDS) {
            throw new ApiException(
                ErrorCodes::TOKEN_EXPIRED,
                'The access token has expired.',
                401,
            );
        }

        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > $now + self::LEEWAY_SECONDS) {
            throw self::invalid();
        }

        if (isset($payload['iat']) && is_numeric($payload['iat']) && (int) $payload['iat'] > $now + self::LEEWAY_SECONDS) {
            throw self::invalid();
        }

        if (($payload['iss'] ?? null) !== config('swasthya.auth.jwt.issuer')) {
            throw self::invalid();
        }

        if (($payload['aud'] ?? null) !== config('swasthya.auth.jwt.audience')) {
            throw self::invalid();
        }

        return $payload;
    }

    /**
     * The active signing key: SWASTHYA_AUTH_JWT_SECRET when configured,
     * otherwise a stable APP_KEY-derived key for local/testing (fail-closed
     * on APP_KEY rotation — never a committed secret).
     */
    public static function key(): string
    {
        $secret = (string) config('swasthya.auth.jwt.secret');

        if ($secret !== '') {
            return $secret;
        }

        return hash_hmac('sha256', self::KEY_DERIVATION_CONTEXT, (string) config('app.key'));
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    private static function encode(array $header, array $payload, string $key): string
    {
        $headerRaw = self::b64encode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadRaw = self::b64encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $headerRaw.'.'.$payloadRaw.'.'.self::sign("{$headerRaw}.{$payloadRaw}", $key);
    }

    private static function sign(string $data, string $key): string
    {
        return self::b64encode(hash_hmac('sha256', $data, $key, true));
    }

    private static function b64encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }

    private static function invalid(): ApiException
    {
        return new ApiException(
            ErrorCodes::INVALID_TOKEN,
            'The access token is invalid.',
            401,
        );
    }
}
