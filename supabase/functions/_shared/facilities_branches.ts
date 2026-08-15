/**
 * The `facilities:branches` domain function (pure request handler, Phase 34)
 * — the facility-scoped branch read, mirroring the established Laravel
 * contract exactly (BranchController::index — the
 * `facilities/{facility}/branches` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'branch:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:branch:view` — a DISTINCT capability from
 *     `patient:view` / `insurance:view` / `consent:view` / `document:view`
 *     / `department:view`; held by the support_agent / org_admin /
 *     hospital_admin / branch_manager roles in the RolePermissionSeeder —
 *     the seeded receptionist / billing_clerk / doctor / nurse roles do
 *     NOT hold it). A principal with the related view permissions alone is
 *     DENIED — the gate is `branch:view`;
 *  3. the facility id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the
 *     authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::facility($key,
 *     write: false)` EXACTLY (the facility-scoped sibling of Phase 33's
 *     organization check): a facility that does not exist →
 *     `404 NOT_FOUND` **'Facility not found.'** (the ApiException thrown
 *     directly by AccessCheck); a facility that exists but is outside the
 *     caller's scope — another tenant, OR a facility-scoped principal
 *     requesting another facility — → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing
 *     selector → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the branches are read under the claims (branches is
 *     TENANT_ONLY — the select policy is `tenant_id = TENANT`; the
 *     FACILITY scoping is the query, not RLS — the exact Laravel
 *     `->where('facility_id', $facility->getKey())`), bound to the
 *     verified facility id and ordered by name ascending — the exact
 *     `->orderBy('name')`;
 *  6. present ONLY the approved branch fields — the exact
 *     BranchController::present() map: {id, facilityId, name, code,
 *     status}. **`facilityId` renders NULL** — the Laravel index query
 *     selects ONLY `['id', 'name', 'code', 'status']`, so `facility_id` is
 *     never hydrated on the model and `present()` reads an un-hydrated
 *     attribute → null. This is the LITERAL index output (the store/show
 *     routes hydrate the full model and return the real facility id; the
 *     index read does not). `status` is active|inactive. NO status filter
 *     — both lifecycle statuses are presented. NO related data, NO
 *     actor/correlation fields, NO tenant/timestamp/audit metadata;
 *  7. NO audit — BranchController::index records no audit event
 *     (`branch.created` / `branch.updated` / `branch.deleted` are
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
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented branch (the exact BranchController::present() map). */
export interface BranchRow {
  id: string;
  facilityId: string | null;
  name: string;
  code: string;
  status: string;
}

/** The NOT-FOUND classes a branch read can produce (AccessCheck::facility). */
export type FacilityBranchesResult = BranchRow[] | 'facility-not-found' | null;

export interface FacilitiesBranchesDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped branches read (swasthya_app under the claims; the
   * facility id is a resource selector). Resolves the facility first:
   *   - a nonexistent facility → `'facility-not-found'` → the
   *     404 **'Facility not found.'** (AccessCheck::facility's own
   *     ApiException);
   *   - a facility outside the authoritative scope (another tenant, or a
   *     facility-scoped principal requesting another facility; platform
   *     callers bypass — AccessCheck::facility) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the facility's branches (ordered by name ASC — the exact
   * `->orderBy('name')`; the query is facility-bound — the exact Laravel
   * `->where('facility_id', $facility->getKey())`; branches is TENANT_ONLY
   * so the facility scoping IS the query; NO status filter — active and
   * inactive both return; soft-deleted rows excluded). **`facilityId`
   * renders NULL** — the Laravel index query hydrates only id/name/code/
   * status, so `present()` reads an un-hydrated attribute (the literal
   * index output). No mutation.
   */
  listFacilityBranches: (claims: Claims, facilityId: string) => FacilityBranchesResult;
}

export async function handleFacilitiesBranches(req: Request, deps: FacilitiesBranchesDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:branch:view` — a DISTINCT
  // capability from `patient:view` / `insurance:view` / `consent:view` /
  // `document:view` / `department:view`.
  if (!can(context, 'branch:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const facilityId = facilityIdFromUrl(req);

  // A missing selector can never resolve — 404 'Resource not found.'
  // (never 400/422). Like the Phase 33 organization selector there is NO
  // UUID gate: AccessCheck::facility resolves with `Facility::find($id)`
  // and the route binding is the Laravel 404 source for unknown ids, so
  // any non-empty selector is a lookup target. An unknown/malformed id
  // resolves to null at the dependency → the AccessCheck-layer 404
  // 'Facility not found.' below; a KNOWN facility outside the caller's
  // scope → the deny(read) 404 'Resource not found.'.
  if (facilityId === '') {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS + AccessCheck decide visibility. The id is a resource selector —
  // never authorization scope.
  const branches = deps.listFacilityBranches(claims, facilityId);

  if (branches === 'facility-not-found') {
    // AccessCheck::facility's own NOT_FOUND — the facility does not exist
    // (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Facility not found.', 404, correlationId);
  }

  if (branches === null) {
    // deny(read): the facility exists but is outside the caller's scope —
    // the generic 404 (AccessCheck::facility's out-of-tenant /
    // out-of-facility-scope denial).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact BranchController::index data shape: the bare branch list
  // (already ordered by name ASC by the RLS-scoped read), wrapped in the
  // standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    branches,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/**
 * The facility id is the LAST path segment of
 * `facilities/{facility}/branches` — the same resource-selector convention
 * as the patient reads.
 */
export function facilityIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}
