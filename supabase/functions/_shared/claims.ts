/**
 * The five authoritative RLS claims (Edge Function mirror of
 * backend App\Support\AuthClaims).
 *
 * `request.jwt.claims` must carry EXACTLY these keys, as strings ('true' /
 * 'false' for app_is_platform). The Phase 2 RLS helpers
 * (public.swasthya_rls_*) read them and nothing else. Claims are DERIVED from
 * the server-resolved context only — there is deliberately no path for
 * client-supplied tenant/facility/branch/platform values.
 */
import type { ResolvedContext } from './types.ts';

export const CLAIM_KEYS = [
  'app_user_id',
  'app_tenant_id',
  'app_facility_id',
  'app_branch_id',
  'app_is_platform',
] as const;

export type ClaimKey = (typeof CLAIM_KEYS)[number];

export type Claims = Record<ClaimKey, string>;

/**
 * Build the claims payload from a resolved context. Empty values encode "no
 * context": the RLS helpers resolve them to NULL and policies grant zero
 * access — a safe failure, never a leak.
 */
export function claimsFromContext(context: ResolvedContext): Claims {
  return {
    app_user_id: context.user?.id ?? '',
    app_tenant_id: context.organizationId ?? '',
    app_facility_id: context.facilityId ?? '',
    app_branch_id: context.branchId ?? '',
    app_is_platform: context.isPlatform ? 'true' : 'false',
  };
}

/**
 * Reduce an arbitrary payload (e.g. a verified JWT) to exactly the five RLS
 * claim keys. Unknown keys (role, permissions, sub, …) are dropped, missing
 * keys default to ''. This is the ONLY value ever written into
 * request.jwt.claims.
 */
export function normalizeClaims(payload: Record<string, unknown>): Claims {
  const claims = {} as Claims;
  for (const key of CLAIM_KEYS) {
    const value = payload[key];
    claims[key] = typeof value === 'string' ? value : '';
  }
  return claims;
}

/** Whether a payload carries the full claim set as strings. */
export function claimsComplete(claims: Record<string, unknown>): boolean {
  return CLAIM_KEYS.every((key) => typeof claims[key] === 'string');
}
