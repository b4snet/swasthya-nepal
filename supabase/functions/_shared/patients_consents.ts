/**
 * The `patients:consents` domain function (pure request handler, Phase 31) —
 * the patient-scoped consent read, mirroring the established Laravel
 * contract exactly (ConsentController::index — the
 * `patients/{patient}/consents` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'consent:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:consent:view` — a DISTINCT capability from `patient:view`
 *     and `insurance:view`; held by the receptionist / doctor / org-admin
 *     roles in the RolePermissionSeeder — the seeded billing_clerk does
 *     NOT hold it). A principal with `patient:view` alone is DENIED — the
 *     gate is `consent:view`;
 *  3. the patient id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the patient lookup is claims-scoped (patients is TENANT_FACILITY)
 *     and decides 404 semantics; the consents are read under the same
 *     claims (consents is TENANT_ONLY) bound to the verified patient id
 *     and ordered by version DESC — the exact `->orderByDesc('version')`;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): a patient that does not exist AND a
 *     patient that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved consent fields — the exact
 *     ConsentController::present() map: {id, patientId, consentType,
 *     version, status, scope, givenAt, revokedAt, revocationReason}.
 *     `patientId` IS contract-explicit (the Laravel map presents it).
 *     `scope` is the DECODED jsonb payload (the 'array' cast). `givenAt`
 *     is a nullable ISO timestamp (`given_at?->toIso8601String()`); the
 *     column is NOT NULL but the `?->` keeps the nullable shape.
 *     `revokedAt` and `revocationReason` are nullable. `status` is
 *     active|revoked|expired. NO status filter — active, revoked AND
 *     expired consents are presented (versioned lifecycle — history
 *     outlives the consent). NO other related data, NO actor/correlation
 *     fields;
 *  7. NO audit — ConsentController::index records no audit event
 *     (`patient.consent.captured/revoked` are the write-side events only);
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No pagination (bare `->get()` array). No invented fields.
 * No RLS weakening. No SECURITY DEFINER. No service-role credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { patientIdFromUrl } from './patients_show.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented consent (the exact ConsentController::present() map). */
export interface PatientConsentRow {
  id: string;
  patientId: string;
  consentType: string;
  version: number;
  status: string;
  scope: Record<string, unknown> | unknown[];
  givenAt: string | null;
  revokedAt: string | null;
  revocationReason: string | null;
}

export interface PatientsConsentsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped consents read (swasthya_app under the claims; the
   * patient id is a resource selector). Resolves the patient under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the patient's consents (ordered by
   * version DESC — the exact `->orderByDesc('version')`; `scope` the
   * decoded jsonb payload; `givenAt`/`revokedAt` ISO timestamps, nullable;
   * `revocationReason` nullable; NO status filter — active, revoked and
   * expired all return). No mutation.
   */
  listPatientConsents: (claims: Claims, id: string) => PatientConsentRow[] | null;
}

export async function handlePatientsConsents(req: Request, deps: PatientsConsentsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:consent:view` — a
  // DISTINCT capability from `patient:view` / `insurance:view`.
  if (!can(context, 'consent:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const patientId = patientIdFromUrl(req);

  // A missing or malformed identifier is indistinguishable from a missing
  // resource — 404, never 400/422 (Laravel's implicit binding resolves to
  // the same ModelNotFoundException).
  if (patientId === '' || !isUuid(patientId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope patient resolves to null here
  // and produces the SAME 404 as a nonexistent one (AccessCheck::scoped,
  // reads). The id is a resource selector — never authorization scope.
  const consents = deps.listPatientConsents(claims, patientId);

  if (consents === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact ConsentController::index data shape: the bare consent list
  // (already ordered by version DESC by the RLS-scoped read), wrapped in
  // the standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    consents,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app patient ids are UUIDs (the primary key of public.patients). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
