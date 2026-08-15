/**
 * The `patients:list` domain function (pure request handler, Phase 7) — the
 * FIRST real read-only domain endpoint on the shared pipeline.
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing application permission model — the same
 *     `patient:view` capability the Laravel route gate enforces
 *     (routes/api.php: authorize:patient:view);
 *  3. query patients through the RLS-scoped database path: the injected
 *     `listPatients(claims)` runs as swasthya_app with request.jwt.claims
 *     set from the server-derived claims — the tenants/facilities/branches
 *     the principal can see are decided by the resolved context + RLS,
 *     never by client input;
 *  4. present ONLY the fields of the established patient presentation
 *     contract (PatientController::present — id, mrn, facilityId, fullName,
 *     dateOfBirth, sex, bloodGroup, status, createdAt, updatedAt);
 *  5. return the standard envelope (data/meta/links + correlation ids) and
 *     fail closed on every failure class (401/403 SCOPE_DENIED).
 *
 * The client supplies NO scope: tenant/facility/branch come exclusively
 * from the authoritative context/claims. Forged app_* claims and forged
 * proposal headers are inert (the shared pipeline ignores them).
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** The exact patient fields the read-only list exposes (mirror of the
 *  Laravel presentation contract — no raw PHI beyond the established API). */
export interface PatientRow {
  id: string;
  mrn: string;
  facilityId: string;
  fullName: string;
  dateOfBirth: string | null;
  sex: string | null;
  bloodGroup: string | null;
  status: string;
  createdAt: string | null;
  updatedAt: string | null;
}

export interface PatientsListDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped patient query. Runs as swasthya_app with
   * request.jwt.claims set from `claims`; the caller filters nothing by
   * client input. Returns the visible rows in the PatientRow shape.
   */
  listPatients: (claims: Claims) => PatientRow[];
}

export async function handlePatientsList(req: Request, deps: PatientsListDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // The application authorization layer (defense-in-depth — RLS stays the
  // final boundary). Same capability + same denial contract as the Laravel
  // route gate: 403 SCOPE_DENIED.
  if (!can(context, 'patient:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const patients = deps.listPatients(claims);

  return successEnvelope(
    patients,
    correlationId,
    context,
    { count: patients.length, claimsIssued: claimsComplete(claims) },
  );
}
