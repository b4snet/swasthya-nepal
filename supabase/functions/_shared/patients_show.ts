/**
 * The `patients:show` domain function (pure request handler, Phase 8) — the
 * single-patient read completing the read spine.
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'patient:view')` capability
 *     (mirror of the Laravel route gate `authorize:patient:view`);
 *  3. the patient id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility/branch scope comes exclusively from the
 *     authoritative context/claims;
 *  4. the RLS-scoped single-row query runs as swasthya_app with
 *     request.jwt.claims set — the p_rls_patients_select policy decides
 *     visibility;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): a patient that does not exist AND a
 *     patient that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved fields (PatientRow — the same shape as
 *     patients:list / PatientController::present);
 *  7. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No create/update/delete. No RLS weakening. No SECURITY DEFINER. No
 * service-role credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { PatientRow } from './patients_list.ts';

export interface PatientsShowDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped single-patient query. Runs as swasthya_app with
   * request.jwt.claims set from `claims`; `id` is a pure resource selector.
   * Returns the visible row or null (null covers both nonexistent and
   * out-of-scope — the caller maps both to 404).
   */
  showPatient: (claims: Claims, id: string) => PatientRow | null;
}

/** GoTrue/app patient ids are UUIDs (the primary key of public.patients). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The patient id is the last non-empty segment of the URL path
 *  (mirrors Laravel's `patients/{patient}` route). */
export function patientIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

export async function handlePatientsShow(req: Request, deps: PatientsShowDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate.
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
  // and produces the SAME 404 as a nonexistent one.
  const patient = deps.showPatient(claims, patientId);

  if (patient === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  return successEnvelope(
    patient,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
