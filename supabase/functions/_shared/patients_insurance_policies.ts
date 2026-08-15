/**
 * The `patients:insurance-policies` domain function (pure request handler,
 * Phase 30) — the patient-scoped insurance-policy read, mirroring the
 * established Laravel contract exactly (InsurancePolicyController::index —
 * the `patients/{patient}/insurance-policies` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'insurance:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:insurance:view` — a DISTINCT capability from
 *     `patient:view`; held by the billing clerk / receptionist / doctor /
 *     org-admin roles in the RolePermissionSeeder). A principal with
 *     `patient:view` alone is DENIED — the gate is `insurance:view`;
 *  3. the patient id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the patient lookup is claims-scoped (patients is TENANT_FACILITY)
 *     and decides 404 semantics; the policies are read under the same
 *     claims (insurance_policies is TENANT_ONLY) bound to the verified
 *     patient id, with the payer ref resolved under the same tenant claim
 *     (payers is TENANT_ONLY) and ordered by created_at DESC — the exact
 *     `->orderByDesc('created_at')` with the eager `payer:id,name,code`;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): a patient that does not exist AND a
 *     patient that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved policy fields — the exact
 *     InsurancePolicyController::present() map: {id, patientId, payerId,
 *     payer, policyNumber, coverageType, validFrom, validTo, benefits,
 *     status, lockVersion}. `patientId`/`payerId`/`lockVersion` ARE
 *     contract-explicit (the Laravel map presents them). `payer` is the
 *     {id, name, code} ref, nullable (an out-of-scope payer renders null,
 *     never a leak). `validFrom` is a date string (the column is NOT
 *     NULL); `validTo` is nullable. `benefits` is the DECODED jsonb
 *     payload (the 'array' cast). `status` is active|expired|cancelled. NO
 *     status filter — active, expired AND cancelled policies are presented
 *     (status is a lifecycle, never a deletion). NO other related data, NO
 *     actor/correlation fields;
 *  7. NO audit — InsurancePolicyController::index records no audit event
 *     (`patient.insurance.added/updated/cancelled` are the write-side
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

/** The eager-loaded payer ref (the exact `payer:id,name,code` selection). */
export interface PayerRefRow {
  id: string;
  name: string;
  code: string;
}

/** One presented policy (the exact InsurancePolicyController::present() map). */
export interface InsurancePolicyRow {
  id: string;
  patientId: string;
  payerId: string;
  payer: PayerRefRow | null;
  policyNumber: string;
  coverageType: string;
  validFrom: string | null;
  validTo: string | null;
  benefits: Record<string, unknown> | unknown[];
  status: string;
  lockVersion: number;
}

export interface PatientsInsurancePoliciesDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped policies read (swasthya_app under the claims; the
   * patient id is a resource selector). Resolves the patient under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the patient's policies (ordered by
   * created_at DESC — the exact `->orderByDesc('created_at')`; the payer
   * ref resolves under the same tenant claim — payers is TENANT_ONLY — an
   * out-of-scope payer renders null, never a leak; `validFrom` date
   * string; `validTo` nullable; `benefits` the decoded jsonb payload;
   * NO status filter — active, expired and cancelled all return). No
   * mutation.
   */
  listPatientInsurancePolicies: (claims: Claims, id: string) => InsurancePolicyRow[] | null;
}

export async function handlePatientsInsurancePolicies(req: Request, deps: PatientsInsurancePoliciesDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:insurance:view` — a
  // DISTINCT capability from `patient:view`.
  if (!can(context, 'insurance:view')) {
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
  const policies = deps.listPatientInsurancePolicies(claims, patientId);

  if (policies === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact InsurancePolicyController::index data shape: the bare policy
  // list (already ordered by created_at DESC by the RLS-scoped read),
  // wrapped in the standard envelope. No audit — the Laravel read does not
  // audit.
  return successEnvelope(
    policies,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app patient ids are UUIDs (the primary key of public.patients). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
