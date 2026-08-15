/**
 * The `organizations:rooms` domain function (pure request handler, Phase 37)
 * — the organization-scoped room read, mirroring the established Laravel
 * contract exactly (RoomController::index — the
 * `organizations/{organization}/rooms` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'room:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:room:view` — a DISTINCT capability from the
 *     patient/insurance/consent/document/department/branch/location/ward
 *     reads; held by the support_agent / org_admin / hospital_admin /
 *     branch_manager roles in the RolePermissionSeeder — the seeded
 *     receptionist / billing_clerk / doctor / nurse roles do NOT hold it).
 *     A principal with the related view permissions alone is DENIED — the
 *     gate is `room:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility/branch scope comes exclusively
 *     from the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33/35/36 reads): an
 *     organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the rooms are read under the claims (rooms is
 *     TENANT_FACILITY_BRANCH — the select policy is `tenant_id = TENANT
 *     AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS
 *     NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the
 *     verified organization id, eager-loaded with the ward reference
 *     (the exact `->with('ward:id,code,name')`), and ordered by name
 *     ascending — the exact `->orderBy('name')`. The facility filter is
 *     applied ONLY when the caller has a facility scope (the exact
 *     `! $context->isPlatform && $context->facilityId() !== null` guard —
 *     org-level / platform callers see every facility of the tenant);
 *  6. present ONLY the approved room fields — the exact
 *     RoomController::index map: {id, facilityId, branchId, wardId, ward,
 *     name, code, roomType, dailyRateMinor, currency, status}. The
 *     controller performs NO partial select — the full model hydrates —
 *     so `facilityId`/`branchId`/`wardId` carry the real values (branchId
 *     nullable — added by tenancy_v2; facility_id/ward_id are NOT NULL in
 *     the base schema). `ward` is the eager ref {id, code, name} (null
 *     only if the composite-FK relation cannot resolve). `roomType` ∈
 *     general/private/semi_private/icu/other. `dailyRateMinor` (integer
 *     minor units) and `currency` (3-char) are nullable. `status` is
 *     active|inactive. NO status filter — both lifecycle statuses are
 *     presented. NO other related data, NO actor/correlation fields, NO
 *     tenant/timestamp/audit metadata;
 *  7. NO audit — RoomController::index records no audit event
 *     (`room.created` / `room.updated` / rate-change events are write-side
 *     only);
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

/** The eager ward reference (the exact `with('ward:id,code,name')` ref). */
export interface RoomWardRef {
  id: string;
  code: string;
  name: string;
}

/** One presented room (the exact RoomController::index map). */
export interface RoomRow {
  id: string;
  facilityId: string;
  branchId: string | null;
  wardId: string;
  ward: RoomWardRef | null;
  name: string;
  code: string;
  roomType: string;
  dailyRateMinor: number | null;
  currency: string | null;
  status: string;
}

/** The NOT-FOUND classes a room read can produce (AccessCheck::organization). */
export type OrganizationRoomsResult = RoomRow[] | 'organization-not-found' | null;

export interface OrganizationsRoomsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped rooms read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's rooms (ordered by name ASC — the exact
   * `->orderBy('name')`, with the eager ward ref `with('ward:id,code,name')`;
   * the facility filter applied ONLY when the caller has a facility claim;
   * branch visibility per the TENANT_FACILITY_BRANCH select policy —
   * `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)`; NO
   * status filter — active and inactive both return; soft-deleted rows
   * excluded). No mutation.
   */
  listOrganizationRooms: (claims: Claims, organizationId: string) => OrganizationRoomsResult;
}

export async function handleOrganizationsRooms(req: Request, deps: OrganizationsRoomsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:room:view` — a DISTINCT
  // capability from the patient/insurance/consent/document/department/
  // branch/location/ward reads.
  if (!can(context, 'room:view')) {
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
  // Like the Phase 33/35/36 organization selectors there is NO UUID gate:
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
  const rooms = deps.listOrganizationRooms(claims, organizationId);

  if (rooms === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (rooms === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact RoomController::index data shape: the bare room list (already
  // ordered by name ASC by the RLS-scoped read), wrapped in the standard
  // envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    rooms,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
