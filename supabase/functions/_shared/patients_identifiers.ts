/**
 * The `patients:identifiers` domain function (pure request handler, Phase
 * 28) — the patient-scoped identity-document read, mirroring the established
 * Laravel contract exactly (PatientIdentifierController::index — the
 * `patients/{patient}/identifiers` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'patient:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:patient:view` — the same gate as `patients:show` and
 *     `patients:timeline`);
 *  3. the patient id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the patient lookup is claims-scoped (patients is TENANT_FACILITY)
 *     and decides 404 semantics; the identifiers are read under the same
 *     claims (patient_identifiers is TENANT_ONLY) bound to the verified
 *     patient id and ordered by created_at DESC — the exact
 *     `->orderByDesc('created_at')`;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): a patient that does not exist AND a
 *     patient that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved identifier fields — the exact
 *     PatientIdentifierController::index map: {id, type, value,
 *     issuingCountry, isVerified, status}. `value` is the DECRYPTED
 *     plaintext as the EncryptedString cast delivers it to the Laravel
 *     controller (the RLS-scoped dependency carries the cast boundary —
 *     see the adapter notes; the edge never holds the Laravel app key, so
 *     live decryption is classified REQUIRES REAL SUPABASE). `issuingCountry`
 *     is nullable; `isVerified` is a boolean; `status` is active|superseded.
 *     NO status filter — both active and superseded identifiers are
 *     presented (the Laravel query has no status where). NO related data,
 *     NO actor/correlation fields;
 *  7. NO audit — PatientIdentifierController::index records no audit event
 *     (`patient.identifier.added` is the store-side write event only);
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

/** One presented identifier (the exact PatientIdentifierController::index map). */
export interface PatientIdentifierRow {
  id: string;
  type: string;
  value: string;
  issuingCountry: string | null;
  isVerified: boolean;
  status: string;
}

export interface PatientsIdentifiersDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped identifiers read (swasthya_app under the claims; the
   * patient id is a resource selector). Resolves the patient under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the patient's identifiers (ordered
   * by created_at descending — the exact `->orderByDesc('created_at')`;
   * `value` is the decrypted plaintext, the EncryptedString cast boundary
   * the dependency carries; `issuingCountry` nullable; `isVerified`
   * boolean; NO status filter — active and superseded both return). No
   * mutation.
   */
  listPatientIdentifiers: (claims: Claims, id: string) => PatientIdentifierRow[] | null;
}

export async function handlePatientsIdentifiers(req: Request, deps: PatientsIdentifiersDeps): Promise<Response> {
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
  const identifiers = deps.listPatientIdentifiers(claims, patientId);

  if (identifiers === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact PatientIdentifierController::index data shape: the bare
  // identifier list (already ordered by created_at DESC by the RLS-scoped
  // read), wrapped in the standard envelope. No audit — the Laravel read
  // does not audit.
  return successEnvelope(
    identifiers,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app patient ids are UUIDs (the primary key of public.patients). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
