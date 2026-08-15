/**
 * The `patients:documents` domain function (pure request handler, Phase 32)
 * — the patient-scoped document-metadata read, mirroring the established
 * Laravel contract exactly (PatientDocumentController::index — the
 * `patients/{patient}/documents` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'document:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:document:view` — a DISTINCT capability from `patient:view`
 *     / `insurance:view` / `consent:view`; held by the receptionist /
 *     doctor / org-admin roles in the RolePermissionSeeder — the seeded
 *     billing_clerk does NOT hold it). A principal with `patient:view`
 *     alone is DENIED — the gate is `document:view`;
 *  3. the patient id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the patient lookup is claims-scoped (patients is TENANT_FACILITY)
 *     and decides 404 semantics; the documents are read under the same
 *     claims (patient_documents is TENANT_ONLY) bound to the verified
 *     patient id and ordered by created_at DESC — the exact
 *     `->orderByDesc('created_at')`;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): a patient that does not exist AND a
 *     patient that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved document fields — the exact
 *     PatientDocumentController::present() map: {id, patientId,
 *     documentType, mimeType, sizeBytes, checksum, status, uploadedAt,
 *     expiresAt, retentionClass}. `patientId` IS contract-explicit (the
 *     Laravel map presents it). `mimeType`/`sizeBytes`/`checksum`/
 *     `expiresAt`/`retentionClass` are nullable (staged metadata). `status`
 *     is staged|available|archived|purged. NO status filter — all four
 *     lifecycle statuses are presented. **`objectKey` is DELIBERATELY
 *     ABSENT** — object storage does not exist yet (the Laravel comment:
 *     records are honestly `staged` with no object key; no endpoint claims
 *     a file can be downloaded) — the storage pointer never crosses this
 *     boundary and there is NO encrypted field / crypto boundary in this
 *     contract. NO other related data, NO actor/correlation fields;
 *  7. NO audit — PatientDocumentController::index records no audit event
 *     (`patient.document.added` is the store-side write event only);
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

/** One presented document (the exact PatientDocumentController::present() map). */
export interface PatientDocumentRow {
  id: string;
  patientId: string;
  documentType: string;
  mimeType: string | null;
  sizeBytes: number | null;
  checksum: string | null;
  status: string;
  uploadedAt: string | null;
  expiresAt: string | null;
  retentionClass: string | null;
}

export interface PatientsDocumentsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped documents read (swasthya_app under the claims; the
   * patient id is a resource selector). Resolves the patient under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the patient's document metadata
   * (ordered by created_at DESC — the exact `->orderByDesc('created_at')`;
   * `mimeType`/`sizeBytes`/`checksum`/`expiresAt`/`retentionClass`
   * nullable; `uploadedAt`/`expiresAt` ISO timestamps; NO status filter —
   * staged, available, archived and purged all return; the storage pointer
   * `objectKey` is deliberately NOT exposed — the Laravel contract does not
   * present it). No mutation.
   */
  listPatientDocuments: (claims: Claims, id: string) => PatientDocumentRow[] | null;
}

export async function handlePatientsDocuments(req: Request, deps: PatientsDocumentsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:document:view` — a
  // DISTINCT capability from `patient:view` / `insurance:view` /
  // `consent:view`.
  if (!can(context, 'document:view')) {
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
  const documents = deps.listPatientDocuments(claims, patientId);

  if (documents === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact PatientDocumentController::index data shape: the bare
  // document list (already ordered by created_at DESC by the RLS-scoped
  // read), wrapped in the standard envelope. No audit — the Laravel read
  // does not audit.
  return successEnvelope(
    documents,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app patient ids are UUIDs (the primary key of public.patients). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
