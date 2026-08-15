/**
 * The `encounters:show` domain function (pure request handler, Phase 20) —
 * the single-encounter READ, mirroring the established Laravel contract
 * exactly (EncounterController::show — the `encounters/{encounter}` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'encounter:view')` capability
 *     (mirror of the Laravel route gate `authorize:encounter:view` — the same
 *     gate as the encounter notes read);
 *  3. the encounter id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped single-row read runs as swasthya_app with
 *     request.jwt.claims set — the claims-scoped encounters policy decides
 *     visibility (encounters is TENANT_FACILITY);
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): an encounter that does not exist AND an
 *     encounter that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved fields (EncounterShowRow — the exact
 *     EncounterController::present shape: id, facilityId, patientId,
 *     appointmentId, providerStaffId, type, status, startedAt, endedAt,
 *     signedAt, lockVersion — with the three ISO timestamps nullable). The
 *     Laravel show contract carries NO related data (no notes, charges,
 *     patient, or invoice objects);
 *  7. audit `encounter.viewed` with the exact Laravel payload
 *     {patientId} — EncounterController::show parity (reads ARE audited in
 *     the Laravel contract);
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No RLS weakening. No SECURITY DEFINER. No service-role
 * credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { encounterIdFromUrl } from './encounters_sign.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AuditEventInput } from './appointments_create.ts';

/** The RLS-visible encounter the handler reads for scope + presentation
 * (mirror of the encounters columns the Laravel presenter uses). */
export interface EncounterShowRow {
  id: string;
  facilityId: string;
  patientId: string;
  appointmentId: string | null;
  providerStaffId: string;
  type: string;
  status: string;
  startedAt: string;
  endedAt: string | null;
  signedAt: string | null;
  lockVersion: number;
}

export interface EncountersShowDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped single-encounter read (swasthya_app under the claims;
   * out-of-scope ≡ nonexistent → null). Runs as swasthya_app with
   * request.jwt.claims set; the explicit tenant/facility WHERE is
   * defense-in-depth. No mutation.
   */
  showEncounter: (claims: Claims, id: string) => EncounterShowRow | null;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

/** GoTrue/app encounter ids are UUIDs (the primary key of public.encounters). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The encounter id is the last non-empty URL segment (route parity with
 * Laravel's `encounters/{encounter}`). */
export function encounterShowIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

export async function handleEncountersShow(req: Request, deps: EncountersShowDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:encounter:view`.
  if (!can(context, 'encounter:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const encounterId = encounterShowIdFromUrl(req);

  // A missing or malformed identifier is indistinguishable from a missing
  // resource — 404, never 400/422 (Laravel's implicit binding resolves to
  // the same ModelNotFoundException).
  if (encounterId === '' || !isUuid(encounterId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope encounter resolves to null here
  // and produces the SAME 404 as a nonexistent one (AccessCheck::scoped,
  // reads). The id is a resource selector — never authorization scope.
  const encounter = deps.showEncounter(claims, encounterId);

  if (encounter === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. `encounter.viewed`
  // with the exact Laravel payload {patientId} — EncounterController::show
  // parity (reads are audited in the Laravel contract).
  await deps.recordAudit({
    action: 'encounter.viewed',
    resourceType: 'encounter',
    resourceId: encounter.id,
    actorId: user.id,
    tenantId: context.organizationId ?? '',
    facilityId: encounter.facilityId,
    correlationId,
    payload: { patientId: encounter.patientId },
  });

  // The exact EncounterController::present data shape — no related data.
  return successEnvelope(
    encounter,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
