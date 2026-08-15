/**
 * The `organizations:beds` domain function (pure request handler, Phase 38)
 * — the organization-scoped bed read, mirroring the established Laravel
 * contract exactly (BedController::index — the
 * `organizations/{organization}/beds` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'bed:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:bed:view` — a DISTINCT capability from the
 *     patient/insurance/consent/document/department/branch/location/ward/
 *     room reads; held by the support_agent / org_admin / hospital_admin /
 *     branch_manager roles in the RolePermissionSeeder — the seeded
 *     receptionist / billing_clerk / doctor / nurse roles do NOT hold it).
 *     A principal with the related view permissions alone is DENIED — the
 *     gate is `bed:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility/branch scope comes exclusively
 *     from the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33/35/36/37 reads): an
 *     organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the beds are read under the claims (beds is
 *     TENANT_FACILITY_BRANCH — the select policy is `tenant_id = TENANT
 *     AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS
 *     NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the
 *     verified organization id, eager-loaded with the room reference
 *     (the exact `->with('room:id,code,name,ward_id')`), and ordered by
 *     `bed_code` ascending — the exact `->orderBy('bed_code')`. The
 *     facility filter is applied ONLY when the caller has a facility scope
 *     (the exact `! $context->isPlatform && $context->facilityId() !==
 *     null` guard — org-level / platform callers see every facility of the
 *     tenant);
 *  6. present ONLY the approved bed fields — the exact
 *     BedController::index map: {id, facilityId, branchId, roomId, room,
 *     bedCode, status, lockVersion}. The controller performs NO partial
 *     select — the full model hydrates — so `facilityId`/`roomId` carry
 *     the real values (NOT NULL in the base schema) and `branchId` is
 *     nullable (tenancy_v2). `room` is the eager ref {id, code, name}
 *     (null only if the composite-FK relation cannot resolve — the eager
 *     load selects `ward_id` but the presented ref renders exactly
 *     id/code/name). `bedCode` is the string(20) bed code. `status` ∈
 *     available/occupied/reserved/cleaning/out_of_service (the BedStatus
 *     state machine). `lockVersion` is the optimistic-locking counter
 *     (bigint, default 0) — CONTRACT-EXPLICIT (presented by Laravel, so it
 *     is presented here). NO status filter — every lifecycle status is
 *     presented. Beds are NEVER soft-deleted (`out_of_service` is a
 *     status, not a deletion — no `deleted_at` column) — the read returns
 *     every bed row of the scope. NO other related data, NO
 *     actor/correlation fields, NO tenant/timestamp/audit metadata;
 *  7. NO audit — BedController::index records no audit event
 *     (`bed.created` / `bed.updated` / state-transition events are
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

/** The eager room reference (the exact `with('room:id,code,name,ward_id')` ref — presented as id/code/name). */
export interface BedRoomRef {
  id: string;
  code: string;
  name: string;
}

/** One presented bed (the exact BedController::index map). */
export interface BedRow {
  id: string;
  facilityId: string;
  branchId: string | null;
  roomId: string;
  room: BedRoomRef | null;
  bedCode: string;
  status: string;
  lockVersion: number;
}

/** The NOT-FOUND classes a bed read can produce (AccessCheck::organization). */
export type OrganizationBedsResult = BedRow[] | 'organization-not-found' | null;

export interface OrganizationsBedsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped beds read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's beds (ordered by `bed_code` ASC — the exact
   * `->orderBy('bed_code')`, with the eager room ref
   * `with('room:id,code,name,ward_id')`; the facility filter applied ONLY
   * when the caller has a facility claim; branch visibility per the
   * TENANT_FACILITY_BRANCH select policy — `(branch_id IS NULL OR
   * branch_id = BRANCH OR BRANCH IS NULL)`; NO status filter — every
   * lifecycle status returns; beds are NEVER soft-deleted (no deleted_at
   * filter — `out_of_service` is a status); `lockVersion` presented).
   * No mutation.
   */
  listOrganizationBeds: (claims: Claims, organizationId: string) => OrganizationBedsResult;
}

export async function handleOrganizationsBeds(req: Request, deps: OrganizationsBedsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:bed:view` — a DISTINCT
  // capability from the patient/insurance/consent/document/department/
  // branch/location/ward/room reads.
  if (!can(context, 'bed:view')) {
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
  // Like the Phase 33/35/36/37 organization selectors there is NO UUID
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
  const beds = deps.listOrganizationBeds(claims, organizationId);

  if (beds === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (beds === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact BedController::index data shape: the bare bed list (already
  // ordered by bed_code ASC by the RLS-scoped read), wrapped in the
  // standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    beds,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
