/**
 * The `me` / `my-context` domain-safe function (pure request handler,
 * Phase 6).
 *
 * The FIRST domain-facing function on the shared pipeline: it proves the
 * authenticated identity, the server-derived context, and the authoritative
 * claims WITHOUT touching any business data. Its response is deliberately
 * small and safe:
 *
 *   data.me       — id / email / status of the resolved application user;
 *   data.context  — kind (platform | support | tenant), organizationId,
 *                   facilityId, branchId, supportSessionId — ALL resolved
 *                   server-side (proposals validated, never trusted);
 *   data.claimsIssued — whether the five RLS claims are complete;
 *   data.correlationId;
 *   meta.context  — the standard context echo (same as every function).
 *
 * Never returned: the JWT, the JWT secret, passwords/hashes, service-role
 * keys, raw permissions, DB credentials, or audit data.
 */
import { claimsComplete } from './claims.ts';
import { success as successEnvelope } from './envelope.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

export async function handleMe(req: Request, deps: HealthAuthDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Deployment wiring (NOT executed in the local harness): set the claims on
  // the least-privilege connection before any query — RLS stays the final
  // boundary for whatever the function reads.
  //   await db`select set_config('request.jwt.claims', ${JSON.stringify(claims)}, true)`

  return successEnvelope(
    {
      me: {
        id: user.id,
        email: user.email ?? null,
        status: user.status,
      },
      context: {
        kind: context.kind,
        organizationId: context.organizationId,
        facilityId: context.facilityId,
        branchId: context.branchId,
        supportSessionId: context.supportSessionId,
      },
      claimsIssued: true,
      correlationId,
    },
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
