/**
 * The `organizations:staff` domain function (pure request handler, Phase 39)
 * — the organization-scoped staff read, mirroring the established Laravel
 * contract exactly (StaffController::index — the
 * `organizations/{organization}/staff` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'staff:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:staff:view` — a DISTINCT capability from the
 *     patient/insurance/consent/document/department/branch/location/ward/
 *     room/bed reads; held by the support_agent / org_admin /
 *     hospital_admin / branch_manager roles in the RolePermissionSeeder —
 *     the seeded receptionist / billing_clerk / doctor / nurse roles do NOT
 *     hold it). A principal with the related view permissions alone is
 *     DENIED — the gate is `staff:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility scope comes exclusively from
 *     the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33/35/36/37/38 reads):
 *     an organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the staff are read under the claims (staff is **TENANT_FACILITY**
 *     — NOT TENANT_FACILITY_BRANCH: staff has NO branch_id column, so the
 *     select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
 *     FACILITY IS NULL)` — there is NO branch clause), bound to the
 *     verified organization id, eager-loaded with the department reference
 *     (the exact `->with('department:id,code,name')`), and ordered by
 *     `full_name` ascending — the exact `->orderBy('full_name')`. The
 *     facility filter is applied ONLY when the caller has a facility scope
 *     (the exact `! $context->isPlatform && $context->facilityId() !==
 *     null` guard — org-level / platform callers see every facility of the
 *     tenant). A branch proposal is IRRELEVANT to this read — staff has no
 *     branch dimension;
 *  6. present ONLY the approved staff fields — the exact
 *     StaffController::index map: {id, facilityId, departmentId,
 *     department, employeeCode, fullName, designation, status, userId,
 *     hireDate}. The controller performs NO partial select — the full
 *     model hydrates — so `facilityId`/`departmentId` carry the real
 *     values (NOT NULL in the base schema); `user_id` is nullable (plain
 *     FK to the global users catalog); `designation` nullable; `hire_date`
 *     nullable and serialized with the date cast's `toDateString()` →
 *     `YYYY-MM-DD`; `department` is the eager ref {id, code, name} (null
 *     only if the composite-FK relation cannot resolve — e.g. a
 *     soft-deleted department); `status` ∈ active/on_leave/departed (the
 *     Staff status lifecycle). NO status filter — every lifecycle status
 *     is presented. Staff are NEVER soft-deleted (`departed` is a status,
 *     not a deletion — no `deleted_at` column) — the read returns every
 *     staff row of the scope. The `license_number_encrypted` column
 *     (EncryptedString cast, ciphertext at rest) is NEVER selected or
 *     presented — the Laravel index map does not include it; no crypto
 *     boundary is crossed. NO other related data, NO actor/correlation
 *     fields, NO tenant/timestamp/audit metadata;
 *  7. NO audit — StaffController::index records no audit event
 *     (`staff.created` / `staff.updated` / status-transition events are
 *     write-side only);
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
import { organizationIdFromUrl } from './organizations_departments.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** The eager department reference (the exact `with('department:id,code,name')` ref). */
export interface StaffDepartmentRef {
  id: string;
  code: string;
  name: string;
}

/** One presented staff member (the exact StaffController::index map). */
export interface StaffRow {
  id: string;
  facilityId: string;
  departmentId: string;
  department: StaffDepartmentRef | null;
  employeeCode: string;
  fullName: string;
  designation: string | null;
  status: string;
  userId: string | null;
  hireDate: string | null;
}

/** The NOT-FOUND classes a staff read can produce (AccessCheck::organization). */
export type OrganizationStaffResult = StaffRow[] | 'organization-not-found' | null;

export interface OrganizationsStaffDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped staff read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's staff (ordered by `full_name` ASC — the
   * exact `->orderBy('full_name')`, with the eager department ref
   * `with('department:id,code,name')`; the facility filter applied ONLY
   * when the caller has a facility claim; staff is **TENANT_FACILITY** —
   * the select policy is `tenant_id = TENANT AND (facility_id = FACILITY
   * OR FACILITY IS NULL)` — NO branch clause (no branch_id column, so a
   * branch proposal does not narrow); NO status filter — active/on_leave/
   * departed all return; staff are NEVER soft-deleted (no deleted_at
   * filter — `departed` is a status); `license_number_encrypted` NEVER
   * selected/presented; `hireDate` as `YYYY-MM-DD`). No mutation.
   */
  listOrganizationStaff: (claims: Claims, organizationId: string) => OrganizationStaffResult;
}

export async function handleOrganizationsStaff(req: Request, deps: OrganizationsStaffDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:staff:view` — a DISTINCT
  // capability from the patient/insurance/consent/document/department/
  // branch/location/ward/room/bed reads.
  if (!can(context, 'staff:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const organizationId = organizationIdFromUrl(req);

  // A missing identifier can never resolve — 404 'Resource not found.'
  // (the established malformed/nonexistent convention; never 400/422).
  // Like the Phase 33/35/36/37/38 organization selectors there is NO UUID
  // gate: AccessCheck::organization resolves with `find($id)` and the
  // route binding is the Laravel 404 source for unknown ids. An
  // unknown/malformed id resolves to null at the dependency → the
  // AccessCheck-layer 404 'Organization not found.' below; a KNOWN
  // organization outside the caller's scope → the deny(read) 404
  // 'Resource not found.'.
  if (organizationId === '') {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS + AccessCheck decide visibility. The id is a resource selector —
  // never authorization scope.
  const staff = deps.listOrganizationStaff(claims, organizationId);

  if (staff === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (staff === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact StaffController::index data shape: the bare staff list
  // (already ordered by full_name ASC by the RLS-scoped read), wrapped in
  // the standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    staff,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
