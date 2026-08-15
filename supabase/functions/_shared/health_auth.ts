/**
 * The `health-auth` infrastructure function (pure request handler).
 *
 * Implements the Edge Function security contract end-to-end via the shared
 * pipeline (./pipeline.ts — Phase 6 extraction; behavior unchanged from
 * Phase 4):
 *
 *  1. parse Authorization: Bearer <GoTrue access JWT> + correlation id;
 *  2. verify the JWT (signature, alg pinning, expiry, issuer/audience);
 *  3. extract `sub` (UUID) → resolve the application user via
 *     users.auth_subject_id;
 *  4. reject non-active identities (pending/locked/disabled → 403);
 *  5. resolve the server-side context (platform / support / tenant),
 *     validating facility & branch PROPOSALS;
 *  6. reject suspended organizations (403 TENANT_SUSPENDED);
 *  7. build the five authoritative RLS claims;
 *  8. (deployment wiring only) set request.jwt.claims on the DB session and
 *     query as swasthya_app — RLS remains the final boundary;
 *  9. return the minimal diagnostic envelope — NEVER secrets, tokens,
 *     password hashes, or permissions.
 *
 * The handler is dependency-free and pure (all data enters via `deps`), so
 * it runs identically in the local Node harness and inside Deno.
 */
import { claimsComplete } from './claims.ts';
import { success as successEnvelope } from './envelope.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

export type { HealthAuthDeps } from './pipeline.ts';

export async function handleHealthAuth(req: Request, deps: HealthAuthDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Deployment wiring (NOT executed in the local harness): write the claims
  // into request.jwt.claims on the function's least-privilege connection
  // (swasthya_app, NOBYPASSRLS) before any query — the same GUC the Phase 2
  // RLS helpers read. The function is the "pooler" for its own session.
  //   await db`select set_config('request.jwt.claims', ${JSON.stringify(claims)}, true)`
  //   // …queries run under RLS as swasthya_app…

  // Non-sensitive diagnostic envelope only.
  return successEnvelope(
    {
      authenticated: true,
      userResolved: true,
      contextResolved: true,
      correlationId,
    },
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
