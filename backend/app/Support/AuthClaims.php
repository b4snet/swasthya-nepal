<?php

namespace App\Support;

/**
 * Phase 3 — server-side claim generation (SECURITY.md §14, TENANCY.md §7).
 *
 * The five claim keys the RLS layer consumes (Phase 2, 2026_08_13_100200).
 * `request.jwt.claims` must carry EXACTLY these keys, with string values
 * ('true'/'false' for app_is_platform) — that is the contract the Phase 2
 * helpers (public.swasthya_rls_*) read.
 *
 * Trust model: the claim VALUES are derived exclusively from the server-
 * resolved context (AuthClaims::fromContext takes a TenantContext — the
 * immutable object ResolveTenantContext builds from the principal's ACTIVE
 * role assignments). There is deliberately NO input path for tenant_id,
 * facility_id, branch_id, role, permission, or is_platform from the browser:
 * facility/branch headers remain *proposals* that the server validates
 * before they ever reach this factory (TENANCY.md V2 §7).
 *
 * In the Supabase-native architecture the same factory runs inside the
 * edge-function signer: it reads the verified GoTrue JWT (sub → application
 * user), resolves the context server-side, and mints the token this class
 * describes — never trusting client-supplied context.
 */
final class AuthClaims
{
    /**
     * The claim keys, in canonical order. RLS reads these from the
     * request.jwt.claims payload; nothing else may enter that payload.
     *
     * @var list<string>
     */
    public const KEYS = [
        'app_user_id',
        'app_tenant_id',
        'app_facility_id',
        'app_branch_id',
        'app_is_platform',
    ];

    /**
     * Derive the claim payload from a resolved request context.
     *
     * Empty values encode "no context": the RLS helpers resolve them to NULL
     * and the policies grant zero access — a safe failure, never a leak.
     *
     * @return array<string, string>
     */
    public static function fromContext(TenantContext $context): array
    {
        return [
            'app_user_id' => (string) ($context->user?->getKey() ?? ''),
            'app_tenant_id' => (string) ($context->organization?->getKey() ?? ''),
            'app_facility_id' => (string) ($context->facility?->getKey() ?? ''),
            'app_branch_id' => (string) ($context->branch?->getKey() ?? ''),
            'app_is_platform' => $context->isPlatform ? 'true' : 'false',
        ];
    }

    /**
     * Reduce an arbitrary payload (e.g. a verified JWT) to exactly the five
     * RLS claim keys. Unknown keys are dropped, missing keys default to ''.
     * This is the ONLY value ever written into request.jwt.claims.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function normalize(array $payload): array
    {
        $claims = [];

        foreach (self::KEYS as $key) {
            $value = $payload[$key] ?? '';
            $claims[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $claims;
    }

    /**
     * Whether a payload contains the full claim set with string values —
     * used by tests and the signer to assert the contract before issuance.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function isComplete(array $claims): bool
    {
        foreach (self::KEYS as $key) {
            if (! isset($claims[$key]) || ! is_string($claims[$key])) {
                return false;
            }
        }

        return true;
    }
}
