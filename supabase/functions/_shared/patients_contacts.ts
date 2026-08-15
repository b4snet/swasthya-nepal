/**
 * The `patients:contacts` domain function (pure request handler, Phase 29) —
 * the patient-scoped contact read, mirroring the established Laravel
 * contract exactly (PatientContactController::index — the
 * `patients/{patient}/contacts` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'patient:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:patient:view` — the same gate as `patients:show`,
 *     `patients:timeline` and `patients:identifiers`);
 *  3. the patient id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the patient lookup is claims-scoped (patients is TENANT_FACILITY)
 *     and decides 404 semantics; the contacts are read under the same
 *     claims (patient_contacts is TENANT_ONLY) bound to the verified
 *     patient id and ordered by is_primary DESC then created_at ASC — the
 *     exact `->orderByDesc('is_primary')->orderBy('created_at')`;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): a patient that does not exist AND a
 *     patient that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved contact fields — the exact
 *     PatientContactController::present() map: {id, type, value, address,
 *     contactPerson, isPrimary, status}. `value` is the plain nullable
 *     text (phone/email/emergency phone — NOT encrypted); `address` and
 *     `contactPerson` are the DECODED jsonb structured payloads (the
 *     PatientContact 'array' casts); `isPrimary` is a boolean; `status` is
 *     active|superseded. NO status filter — active AND superseded contacts
 *     are presented (the Laravel query has no status where; history is
 *     preserved by superseding, never deleting). NO related data, NO
 *     actor/correlation fields;
 *  7. NO audit — PatientContactController::index records no audit event
 *     (`patient.contact.added/updated` are the store/update-side write
 *     events only);
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

/** One presented contact (the exact PatientContactController::present() map). */
export interface PatientContactRow {
  id: string;
  type: string;
  value: string | null;
  address: Record<string, unknown> | null;
  contactPerson: Record<string, unknown> | null;
  isPrimary: boolean;
  status: string;
}

export interface PatientsContactsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped contacts read (swasthya_app under the claims; the
   * patient id is a resource selector). Resolves the patient under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the patient's contacts (ordered by
   * is_primary DESC then created_at ASC — the exact
   * `->orderByDesc('is_primary')->orderBy('created_at')`; `value` plain
   * nullable text; `address`/`contactPerson` the decoded jsonb structured
   * payloads; `isPrimary` boolean; NO status filter — active and superseded
   * both return). No mutation.
   */
  listPatientContacts: (claims: Claims, id: string) => PatientContactRow[] | null;
}

export async function handlePatientsContacts(req: Request, deps: PatientsContactsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:patient:view`.
  if (!can(context, 'patient:view')) {
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
  const contacts = deps.listPatientContacts(claims, patientId);

  if (contacts === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact PatientContactController::index data shape: the bare contact
  // list (already ordered by is_primary DESC / created_at ASC by the
  // RLS-scoped read), wrapped in the standard envelope. No audit — the
  // Laravel read does not audit.
  return successEnvelope(
    contacts,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app patient ids are UUIDs (the primary key of public.patients). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
