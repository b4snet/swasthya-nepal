/**
 * The `organizations:wards` domain function (pure request handler, Phase 36)
 * — the organization-scoped ward read, mirroring the established Laravel
 * contract exactly (WardController::index — the
 * `organizations/{organization}/wards` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'ward:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:ward:view` — a DISTINCT capability from the
 *     patient/insurance/consent/document/department/branch/location reads;
 *     held by the support_agent / org_admin / hospital_admin /
 *     branch_manager roles in the RolePermissionSeeder — the seeded
 *     receptionist / billing_clerk / doctor / nurse roles do NOT hold it).
 *     A principal with the related view permissions alone is DENIED — the
 *     gate is `ward:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility/branch scope comes exclusively
 *     from the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33/35 reads): an
 *     organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the wards are read under the claims (wards is
 *     TENANT_FACILITY_BRANCH — the select policy is `tenant_id = TENANT
 *     AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS
 *     NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the
 *     verified organization id and ordered by name ascending — the exact
 *     `->orderBy('name')`. The facility filter is applied ONLY when the
 *     caller has a facility scope (the exact `! $context->isPlatform &&
 *     $context->facilityId() !== null` guard — org-level / platform
 *     callers see every facility of the tenant);
 *  6. present ONLY the approved ward fields — the exact
 *     WardController::present() map: {id, facilityId, branchId, name,
 *     code, wardType, status}. `facilityId` / `branchId` ARE
 *     contract-explicit and nullable AND hydrated (the index select
 *     includes them — real values). `wardType` ∈
 *     general/surgery/pediatric/icu/maternity/other. `status` is
 *     active|inactive. NO status filter — both lifecycle statuses are
 *     presented. NO related data, NO actor/correlation fields, NO
 *     tenant/timestamp/audit metadata;
 *  7. NO audit — WardController::index records no audit event
 *     (`ward.created` / `ward.updated` / `ward.deleted` are write-side
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
import { organizationIdFromUrl } from './organizations_departments.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented ward (the exact WardController::present() map). */
export interface WardRow {
  id: string;
  facilityId: string | null;
  branchId: string | null;
  name: string;
  code: string;
  wardType: string;
  status: string;
}

/** The NOT-FOUND classes a ward read can produce (AccessCheck::organization). */
export type OrganizationWardsResult = WardRow[] | 'organization-not-found' | null;

export interface OrganizationsWardsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped wards read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's wards (ordered by name ASC — the exact
   * `->orderBy('name')`; the facility filter applied ONLY when the caller
   * has a facility claim; branch visibility per the TENANT_FACILITY_BRANCH
   * select policy — `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS
   * NULL)`; NO status filter — active and inactive both return; soft-deleted
   * rows excluded; `facilityId` / `branchId` nullable AND hydrated — the
   * index select includes them, so they carry the real values). No mutation.
   */
  listOrganizationWards: (claims: Claims, organizationId: string) => OrganizationWardsResult;
}

export async function handleOrganizationsWards(req: Request, deps: OrganizationsWardsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:ward:view` — a DISTINCT
  // capability from the patient/insurance/consent/document/department/
  // branch/location reads.
  if (!can(context, 'ward:view')) {
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
  const wards = deps.listOrganizationWards(claims, organizationId);

  if (wards === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (wards === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact WardController::index data shape: the bare ward list (already
  // ordered by name ASC by the RLS-scoped read), wrapped in the standard
  // envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    wards,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
