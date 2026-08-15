/**
 * The `organizations:departments` domain function (pure request handler,
 * Phase 33) — the organization-scoped department read, mirroring the
 * established Laravel contract exactly (DepartmentController::index — the
 * `organizations/{organization}/departments` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'department:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:department:view` — a DISTINCT capability from
 *     `patient:view` / `insurance:view` / `consent:view` / `document:view`;
 *     held by the org_admin / hospital_admin / branch_manager /
 *     support_agent roles in the RolePermissionSeeder — the seeded
 *     receptionist / billing_clerk / doctor / nurse roles do NOT hold it).
 *     A principal with `patient:view` alone is DENIED — the gate is
 *     `department:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility/branch scope comes exclusively
 *     from the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (a distinct error class from the patient
 *     reads): an organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). A missing/malformed organization id fails route model
 *     binding in Laravel (ModelNotFoundException) →
 *     `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the departments are read under the claims
 *     (departments is TENANT_FACILITY_BRANCH — the select policy is
 *     `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS
 *     NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS
 *     NULL)`), bound to the verified organization id and ordered by name
 *     ascending — the exact `->orderBy('name')`. The facility filter is
 *     applied ONLY when the caller has a facility scope (the exact
 *     `! $context->isPlatform && $context->facilityId() !== null` guard —
 *     org-level / platform callers see every facility of the tenant);
 *  6. present ONLY the approved department fields — the exact
 *     DepartmentController::present() map: {id, facilityId, branchId,
 *     name, code, status, parentDepartmentId}. `facilityId` / `branchId` /
 *     `parentDepartmentId` ARE contract-explicit and nullable (the Laravel
 *     map presents them). `status` is active|inactive. NO status filter —
 *     both lifecycle statuses are presented (the read is a catalog read,
 *     not a workflow). NO related data, NO actor/correlation fields, NO
 *     tenant/timestamp/audit metadata;
 *  7. NO audit — DepartmentController::index records no audit event
 *     (`department.created` / `department.updated` / `department.deleted`
 *     are write-side events only);
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
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented department (the exact DepartmentController::present() map). */
export interface DepartmentRow {
  id: string;
  facilityId: string | null;
  branchId: string | null;
  name: string;
  code: string;
  status: string;
  parentDepartmentId: string | null;
}

/** The NOT-FOUND classes a department read can produce (AccessCheck::organization). */
export type OrganizationDepartmentsResult = DepartmentRow[] | 'organization-not-found' | null;

export interface OrganizationsDepartmentsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped departments read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's departments (ordered by name ASC — the
   * exact `->orderBy('name')`; the facility filter applied ONLY when the
   * caller has a facility claim; branch visibility per the
   * TENANT_FACILITY_BRANCH select policy — `(branch_id IS NULL OR
   * branch_id = BRANCH OR BRANCH IS NULL)`; NO status filter — active and
   * inactive both return; soft-deleted rows excluded; `facilityId` /
   * `branchId` / `parentDepartmentId` nullable). No mutation.
   */
  listOrganizationDepartments: (claims: Claims, organizationId: string) => OrganizationDepartmentsResult;
}

export async function handleOrganizationsDepartments(req: Request, deps: OrganizationsDepartmentsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:department:view` — a
  // DISTINCT capability from `patient:view` / `insurance:view` /
  // `consent:view` / `document:view`.
  if (!can(context, 'department:view')) {
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
  // NOTE: unlike the patient reads there is NO UUID gate on the
  // organization selector — AccessCheck::organization resolves the
  // organization with `find($id)` and the route binding (ModelNotFound
  // Exception) is the Laravel 404 source for unknown ids, so any non-empty
  // selector is a lookup target. An unknown/malformed id resolves to null
  // at the dependency → the AccessCheck-layer 404 'Organization not
  // found.' below; a KNOWN organization outside the caller's scope → the
  // deny(read) 404 'Resource not found.'.
  if (organizationId === '') {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS + AccessCheck decide visibility. The id is a resource selector —
  // never authorization scope.
  const departments = deps.listOrganizationDepartments(claims, organizationId);

  if (departments === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (departments === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the SAME 404 as a nonexistent one would be wrong here
    // (AccessCheck throws the specific 'Organization not found.' only for
    // the nonexistent case; out-of-scope is the generic denial).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact DepartmentController::index data shape: the bare department
  // list (already ordered by name ASC by the RLS-scoped read), wrapped in
  // the standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    departments,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/**
 * The organization id is the LAST path segment of
 * `organizations/{organization}/departments` — the same resource-selector
 * convention as the patient reads.
 */
export function organizationIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}
