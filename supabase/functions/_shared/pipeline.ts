/**
 * The single authenticated-request pipeline for every Swasthya Edge Function
 * (Phase 6 extraction — previously inline in health_auth.ts).
 *
 * Every function — `health-auth`, `me`, and every future domain function —
 * goes through EXACTLY this path, so the security contract cannot drift
 * between functions:
 *
 *   1. parse Authorization: Bearer <GoTrue access JWT> + correlation id;
 *   2. verify the JWT (signature, alg pinning, expiry, issuer/audience);
 *   3. extract `sub` (a UUID — auth.users.id) → resolve the application
 *      user via users.auth_subject_id (fail closed on missing/unknown);
 *   4. reject non-active identities (pending/locked/disabled → 403);
 *   5. resolve the server-side context from ACTIVE role assignments
 *      (platform / support / tenant), validating facility & branch
 *      PROPOSALS against those assignments;
 *   6. reject suspended organizations (403 TENANT_SUSPENDED);
 *   7. build the five authoritative RLS claims;
 *   8. (deployment wiring only) set request.jwt.claims on the DB session
 *      and query as swasthya_app — RLS remains the final boundary.
 *
 * The caller decides the envelope (health diagnostics vs. a domain payload);
 * this module only authenticates + derives the authoritative context.
 * Client-supplied tenant/facility/branch/platform values and the token's own
 * app_* claims NEVER become claims — only `sub` is read from the token.
 */
import { claimsComplete, claimsFromContext, type Claims } from './claims.ts';
import { resolveContext } from './context.ts';
import { correlationId, error as errorEnvelope } from './envelope.ts';
import { EdgeError, ErrorCodes, JwtError } from './errors.ts';
import { resolveAppUser, type FindUserBySubject } from './identity.ts';
import { verifyJwt } from './jwt.ts';
import type {
  AppUser,
  Assignment,
  BranchInfo,
  FacilityInfo,
  OrganizationInfo,
  ResolvedContext,
  SupportSessionInfo,
} from './types.ts';

export interface HealthAuthDeps {
  /** The Supabase project JWT secret (SUPABASE_JWT_SECRET in deployment). */
  secret: string;
  issuer: string;
  audience: string;
  /** Load the application user by GoTrue subject (users.auth_subject_id). */
  findUserBySubject: FindUserBySubject;
  /** Load the user's ACTIVE role assignments, server-side. */
  loadActiveAssignments: (userId: string) => Assignment[];
  /** Load the user's active support session, if any. */
  activeSupportSession: (userId: string) => SupportSessionInfo | null;
  loadOrganization: (id: string) => OrganizationInfo | null;
  loadFacility: (id: string) => FacilityInfo | null;
  loadBranch: (id: string) => BranchInfo | null;
  /** True on platform-administration routes (api/v1/platform/* equivalent). */
  isPlatformRoute?: (req: Request) => boolean;
}

export interface AuthenticatedRequest {
  /** The request correlation id (incoming or minted). */
  correlationId: string;
  /** The verified JWT payload (used only for `sub`; app_* claims ignored). */
  payload: Record<string, unknown>;
  /** The resolved application user. */
  user: AppUser;
  /** The server-derived context (platform / support / tenant). */
  context: ResolvedContext;
  /** The five authoritative RLS claims, derived ONLY from the context. */
  claims: Claims;
  /** Whether the claims payload is complete (shape check). */
  claimsComplete: boolean;
}

export type AuthenticationResult =
  | { ok: true; result: AuthenticatedRequest }
  | { ok: false; response: Response };

/**
 * Authenticate + resolve an incoming request. Returns the authoritative
 * identity/context/claims on success, or a ready-made error Response on any
 * controlled failure (401/403/500 envelope).
 */
export async function authenticateRequest(
  req: Request,
  deps: HealthAuthDeps,
): Promise<AuthenticationResult> {
  const correlation = correlationId(req);

  try {
    const authorization = req.headers.get('Authorization') ?? '';
    if (!authorization.startsWith('Bearer ') || authorization.slice(7).trim() === '') {
      return { ok: false, response: errorEnvelope(ErrorCodes.INVALID_TOKEN, 'Authentication required.', 401, correlation) };
    }

    const payload = await verifyJwt(authorization.slice(7), {
      secret: deps.secret,
      issuer: deps.issuer,
      audience: deps.audience,
    });

    const sub = typeof payload.sub === 'string' ? payload.sub : '';
    const user = resolveAppUser(sub, deps.findUserBySubject);

    if (user === null) {
      return { ok: false, response: errorEnvelope(
        ErrorCodes.INVALID_TOKEN,
        'The identity is not linked to an application account.',
        401,
        correlation,
      ) };
    }

    const assignments = deps.loadActiveAssignments(user.id);
    const context = resolveContext({
      user,
      assignments,
      isPlatformRoute: deps.isPlatformRoute?.(req) ?? false,
      proposals: {
        facilityId: req.headers.get('X-Swasthya-Facility'),
        branchId: req.headers.get('X-Swasthya-Branch'),
      },
      activeSupportSession: deps.activeSupportSession(user.id),
      deps,
    });

    const claims = claimsFromContext(context);

    return {
      ok: true,
      result: {
        correlationId: correlation,
        payload,
        user,
        context,
        claims,
        claimsComplete: claimsComplete(claims),
      },
    };
  } catch (caught) {
    if (caught instanceof JwtError) {
      return { ok: false, response: errorEnvelope(caught.code, caught.message, caught.status, correlation) };
    }
    if (caught instanceof EdgeError) {
      return { ok: false, response: errorEnvelope(caught.code, caught.message, caught.status, correlation) };
    }
    return { ok: false, response: errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlation) };
  }
}
