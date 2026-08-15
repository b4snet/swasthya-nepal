/**
 * The `organizations:locations` domain function (pure request handler,
 * Phase 35) — the organization-scoped location read, mirroring the
 * established Laravel contract exactly (LocationController::index — the
 * `organizations/{organization}/locations` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'location:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:location:view` — a DISTINCT capability from
 *     `patient:view` / `insurance:view` / `consent:view` / `document:view`
 *     / `department:view` / `branch:view`; held by the support_agent /
 *     org_admin / hospital_admin / branch_manager roles in the
 *     RolePermissionSeeder — the seeded receptionist / billing_clerk /
 *     doctor / nurse roles do NOT hold it). A principal with the related
 *     view permissions alone is DENIED — the gate is `location:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility/branch scope comes exclusively
 *     from the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33 departments read):
 *     an organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the locations are read under the claims (locations is
 *     TENANT_FACILITY_BRANCH — the select policy is `tenant_id = TENANT
 *     AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS
 *     NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the
 *     verified organization id and ordered by name ascending — the exact
 *     `->orderBy('name')`. The facility filter is applied ONLY when the
 *     caller has a facility scope (the exact `! $context->isPlatform &&
 *     $context->facilityId() !== null` guard — org-level / platform
 *     callers see every facility of the tenant);
 *  6. present ONLY the approved location fields — the exact
 *     LocationController::present() map: {id, facilityId, branchId, name,
 *     code, type, status}. `facilityId` / `branchId` ARE contract-explicit
 *     and nullable (the Laravel map presents them; the index select
 *     hydrates them — real values, unlike the Phase 34 branches read where
 *     facility_id is deliberately not selected). `type` ∈
 *     store/waiting_area/nursing_station/procedure_area/other. `status` is
 *     active|inactive. NO status filter — both lifecycle statuses are
 *     presented. NO related data, NO actor/correlation fields, NO
 *     tenant/timestamp/audit metadata;
 *  7. NO audit — LocationController::index records no audit event
 *     (`location.created` / `location.updated` / `location.deleted` are
 *     write-side events only);
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

/** One presented location (the exact LocationController::present() map). */
export interface LocationRow {
  id: string;
  facilityId: string | null;
  branchId: string | null;
  name: string;
  code: string;
  type: string;
  status: string;
}

/** The NOT-FOUND classes a location read can produce (AccessCheck::organization). */
export type OrganizationLocationsResult = LocationRow[] | 'organization-not-found' | null;

export interface OrganizationsLocationsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped locations read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's locations (ordered by name ASC — the exact
   * `->orderBy('name')`; the facility filter applied ONLY when the caller
   * has a facility claim; branch visibility per the TENANT_FACILITY_BRANCH
   * select policy — `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS
   * NULL)`; NO status filter — active and inactive both return; soft-deleted
   * rows excluded; `facilityId` / `branchId` nullable — the index select
   * hydrates them, so they carry the real values). No mutation.
   */
  listOrganizationLocations: (claims: Claims, organizationId: string) => OrganizationLocationsResult;
}

export async function handleOrganizationsLocations(req: Request, deps: OrganizationsLocationsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:location:view` — a
  // DISTINCT capability from the patient/insurance/consent/document/
  // department/branch reads.
  if (!can(context, 'location:view')) {
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
  // Like the Phase 33 organization selector there is NO UUID gate:
  // AccessCheck::organization resolves with `find($id)` and the route
  // binding is the Laravel 404 source for unknown ids. An unknown/malformed
  // id resolves to null at the dependency → the AccessCheck-layer 404
  // 'Organization not found.' below; a KNOWN organization outside the
  // caller's scope → the deny(read) 404 'Resource not found.'.
  if (organizationId === '') {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS + AccessCheck decide visibility. The id is a resource selector —
  // never authorization scope.
  const locations = deps.listOrganizationLocations(claims, organizationId);

  if (locations === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (locations === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact LocationController::index data shape: the bare location list
  // (already ordered by name ASC by the RLS-scoped read), wrapped in the
  // standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    locations,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
