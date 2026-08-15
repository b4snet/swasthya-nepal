/**
 * The `patients:search` domain function (pure request handler, Phase 23) —
 * the candidate patient SEARCH, mirroring the established Laravel contract
 * exactly (PatientController::search + SearchPatientRequest — the
 * `GET patients/search` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'patient:search')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:patient:search` — a DISTINCT capability from patient:view);
 *  3. strict query validation (SearchPatientRequest + ApiRequest strict
 *     mode): ONLY `q` is accepted (unknown parameters → 422 'Field "…" is
 *     not allowed.'); `q` is required, string, min 2, max 255 — validated on
 *     the RAW value, then TRIM-med server-side (Laravel parity); failures
 *     are 422 VALIDATION_ERROR with the exact ApiExceptionMapper detail
 *     codes (REQUIRED / OUT_OF_RANGE);
 *  4. search semantics (PatientController::search): tenant scope always,
 *     `status = 'active'`, facility scope when the caller has a facility
 *     claim (org/platform context searches the whole tenant — RLS
 *     facilityClause parity), `lower(full_name) LIKE '%q%' OR lower(mrn)
 *     LIKE 'q%'` (case-insensitive substring / prefix, LIKE wildcards
 *     unescaped — Laravel parity), scored by `similarity(lower(full_name),
 *     q)` (pg_trgm — the harness simulates the trigram formula, the real
 *     DB proves it), ordered by score DESC, HARD LIMIT 20 (no pagination);
 *  5. result shape: the exact 7-field map {id, mrn, fullName, dateOfBirth,
 *     sex, facilityId (strings), score (rounded to 4 decimals)} — `status`
 *     is filtered but NEVER presented;
 *  6. AUDIT: `patient.searched` with the exact Laravel payload
 *     {resultCount} — recorded on EVERY search (even empty results),
 *     resourceType 'patient_search', resourceId null, attributed to the
 *     actor + authoritative tenant/facility + correlation id;
 *  7. envelope: data = bare array, meta.search.hint — the exact Laravel
 *     hint strings (N candidate(s) found — confirm identity before opening.
 *     / No candidates found.);
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
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AuditEventInput } from './appointments_create.ts';

/** The exact PatientController::search result item (7 fields, all strings
 * except the rounded pg_trgm score). */
export interface PatientSearchRow {
  id: string;
  mrn: string;
  fullName: string;
  dateOfBirth: string;
  sex: string;
  facilityId: string;
  score: number;
}

/** Validation detail — mirror of ApiExceptionMapper::validationDetails. */
export interface SearchValidationDetail {
  field: string;
  code: string;
  message: string;
}

export interface PatientsSearchDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped patient search (swasthya_app under the claims; mirror of
   * PatientController::search): tenant scope always, `status = 'active'`,
   * facility scope only when a facility claim exists, the case-insensitive
   * `lower(full_name) LIKE '%q%' OR lower(mrn) LIKE 'q%'` match, pg_trgm
   * `similarity(lower(full_name), q)` score, score DESC order, hard LIMIT 20.
   * Returns only visible rows.
   */
  searchPatients: (claims: Claims, q: string) => PatientSearchRow[];
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

/** The trimmed search term (Laravel: trim((string) validated('q'))). */
function trimmedTerm(value: string): string {
  return value.trim();
}

export async function handlePatientsSearch(req: Request, deps: PatientsSearchDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:patient:search`.
  if (!can(context, 'patient:search')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  // Strict query validation (SearchPatientRequest + ApiRequest strict mode):
  // ONLY `q` is accepted; unknown parameters are rejected 422.
  const searchParams = new URL(req.url).searchParams;
  const errors: SearchValidationDetail[] = [];
  for (const key of searchParams.keys()) {
    if (key !== 'q') {
      errors.push({ field: key, code: 'VALIDATION_ERROR', message: `Field "${key}" is not allowed.` });
    }
  }
  const rawQ = searchParams.get('q');
  if (rawQ === null) {
    errors.push({ field: 'q', code: 'REQUIRED', message: 'The q field is required.' });
  } else if (rawQ.length < 2) {
    errors.push({ field: 'q', code: 'OUT_OF_RANGE', message: 'The q field must be at least 2 characters.' });
  } else if (rawQ.length > 255) {
    errors.push({ field: 'q', code: 'OUT_OF_RANGE', message: 'The q field must not be greater than 255 characters.' });
  }

  if (errors.length > 0) {
    return errorEnvelope(
      ErrorCodes.VALIDATION_ERROR,
      `${errors.length} field(s) failed validation.`,
      422,
      correlationId,
      errors,
    );
  }

  // Trim after validation — Laravel validates the RAW value, then trims.
  const q = trimmedTerm(rawQ as string);

  // RLS decides visibility and scope; the match/score/order/limit mirror the
  // Laravel query (applied inside the claims-scoped read).
  const results = deps.searchPatients(claims, q);

  // AUDIT — `patient.searched` with the exact Laravel payload
  // {resultCount}, recorded on EVERY search (Laravel parity, including
  // empty results). resourceId is null (the event is a search, not a
  // resource read).
  await deps.recordAudit({
    action: 'patient.searched',
    resourceType: 'patient_search',
    resourceId: null,
    actorId: user.id,
    tenantId: context.organizationId ?? '',
    facilityId: context.facilityId ?? '',
    correlationId,
    payload: { resultCount: results.length },
  });

  // The exact Laravel envelope: bare array + the meta.search.hint strings.
  const hint = results.length > 0
    ? `${results.length} candidate(s) found — confirm identity before opening.`
    : 'No candidates found.';

  return successEnvelope(
    results,
    correlationId,
    context,
    { search: { hint }, claimsIssued: claimsComplete(claims) },
  );
}
