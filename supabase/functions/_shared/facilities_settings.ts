/**
 * The `facilities:settings` domain function (pure request handler, Phase 45)
 * — the facility-scoped configuration read, mirroring the established
 * Laravel contract exactly (FacilitySettingsController::index — the
 * `facilities/{facility}/settings` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'settings:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:settings:view` — a DISTINCT capability from
 *     `patient:view` / `insurance:view` / `consent:view` / `document:view`
 *     / `department:view` / `branch:view` / `location:view` / `ward:view`
 *     / `room:view` / `bed:view` / `staff:view` / `service:view` /
 *     `payer:view` / `medication:view` / `schedule:view`; held by the
 *     support_agent / org_admin / hospital_admin / branch_manager roles in
 *     the RolePermissionSeeder — the seeded receptionist / billing_clerk /
 *     doctor / nurse roles do NOT hold it). A principal with the related
 *     view permissions alone is DENIED — the gate is `settings:view`;
 *  3. the facility id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the
 *     authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::facility($key,
 *     write: false)` EXACTLY (the facility-scoped sibling of Phase 33's
 *     organization check, identical to the Phase 34 branches read): a
 *     facility that does not exist → `404 NOT_FOUND` **'Facility not
 *     found.'** (the ApiException thrown directly by AccessCheck); a
 *     facility that exists but is outside the caller's scope — another
 *     tenant, OR a facility-scoped principal requesting another facility —
 *     → `deny(read)` → `404 NOT_FOUND` **'Resource not found.'**
 *     (existence is never leaked). Platform callers bypass the scope
 *     check. A missing selector → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the settings are read under the claims (facility_settings is
 *     **TENANT_FACILITY** — the select policy is `tenant_id = TENANT AND
 *     (facility_id = FACILITY OR FACILITY IS NULL)`; the facility scoping
 *     is BOTH the query (the exact Laravel `->where('facility_id',
 *     $facility->getKey())` bound to the VERIFIED facility id) AND the RLS
 *     facility clause — an org-level claim (facility NULL) sees the whole
 *     tenant, and the query's verified-facility binding narrows the read
 *     to exactly that facility), and ordered by `key` ascending — the
 *     exact `->orderBy('key')`;
 *  6. present ONLY the approved setting entry — the exact
 *     FacilitySettingsController::index mapWithKeys shape: a JSON OBJECT
 *     keyed by setting key, each entry `{value, version, updatedAt}`:
 *     `value` is the jsonb `value` column decoded (the `value` cast is
 *     'array' — jsonb is already JSON; the decoded payload passes through
 *     unchanged); `version` is the integer optimistic-lock counter (every
 *     change bumps it — 1 on create); `updatedAt` is the `updated_at`
 *     timestamp cast to Carbon and formatted `toIso8601String()` — e.g.
 *     '2026-03-02T10:00:00+00:00' — or null when `updated_at` is null
 *     (the `?->` null guard). NO status field exists (settings have no
 *     lifecycle status), NO deleted_at (never soft-deleted — removing a
 *     key is an audited state change), NO pagination (a keyed map, not a
 *     list). NO related data, NO actor/correlation fields, NO
 *     tenant/timestamp/audit metadata beyond the presented `updatedAt`;
 *  7. NO audit — FacilitySettingsController::index records no audit event
 *     (`facility.settings.updated` / `facility.settings.deleted` are
 *     write-side events only);
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No invented fields. No RLS weakening. No SECURITY
 * DEFINER. No service-role credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { facilityIdFromUrl } from './facilities_branches.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented facility setting (the exact mapWithKeys entry). */
export interface FacilitySettingEntry {
  value: unknown;
  version: number;
  updatedAt: string | null;
}

/**
 * The exact FacilitySettingsController::index data shape: a JSON OBJECT
 * keyed by setting key (mapWithKeys) — never an array.
 */
export type FacilitySettingsMap = Record<string, FacilitySettingEntry>;

/** The NOT-FOUND classes a settings read can produce (AccessCheck::facility). */
export type FacilitySettingsResult = FacilitySettingsMap | 'facility-not-found' | null;

export interface FacilitiesSettingsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped settings read (swasthya_app under the claims; the
   * facility id is a resource selector). Resolves the facility first:
   *   - a nonexistent facility → `'facility-not-found'` → the
   *     404 **'Facility not found.'** (AccessCheck::facility's own
   *     ApiException);
   *   - a facility outside the authoritative scope (another tenant, or a
   *     facility-scoped principal requesting another facility; platform
   *     callers bypass — AccessCheck::facility) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the facility's settings as the exact mapWithKeys object
   * (keyed by setting key; ordered by key ASC — the exact
   * `->orderBy('key')`; the query is facility-bound — the exact Laravel
   * `->where('facility_id', $facility->getKey())`; facility_settings is
   * TENANT_FACILITY so the RLS facility clause + the verified-facility
   * binding scope the read; NO status field exists, NO soft-deletes —
   * nothing is ever excluded). Each entry is exactly `{value, version,
   * updatedAt}` — `value` the decoded jsonb, `version` the integer
   * counter, `updatedAt` the `toIso8601String()` timestamp or null. No
   * mutation.
   */
  listFacilitySettings: (claims: Claims, facilityId: string) => FacilitySettingsResult;
}

export async function handleFacilitiesSettings(req: Request, deps: FacilitiesSettingsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:settings:view` — a
  // DISTINCT capability from the patient/insurance/consent/document/
  // department/branch/location/ward/room/bed/staff/service/payer/
  // medication/schedule reads.
  if (!can(context, 'settings:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const facilityId = facilityIdFromUrl(req);

  // A missing selector can never resolve — 404 'Resource not found.'
  // (never 400/422). Like the Phase 34 facility selector there is NO UUID
  // gate: AccessCheck::facility resolves with `Facility::find($id)` and the
  // route binding is the Laravel 404 source for unknown ids, so any
  // non-empty selector is a lookup target. An unknown/malformed id resolves
  // to null at the dependency → the AccessCheck-layer 404 'Facility not
  // found.' below; a KNOWN facility outside the caller's scope → the
  // deny(read) 404 'Resource not found.'.
  if (facilityId === '') {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS + AccessCheck decide visibility. The id is a resource selector —
  // never authorization scope.
  const settings = deps.listFacilitySettings(claims, facilityId);

  if (settings === 'facility-not-found') {
    // AccessCheck::facility's own NOT_FOUND — the facility does not exist
    // (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Facility not found.', 404, correlationId);
  }

  if (settings === null) {
    // deny(read): the facility exists but is outside the caller's scope —
    // the generic 404 (AccessCheck::facility's out-of-tenant /
    // out-of-facility-scope denial).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact FacilitySettingsController::index data shape: the keyed
  // settings object (already ordered by key ASC by the RLS-scoped read),
  // wrapped in the standard envelope. No audit — the Laravel read does not
  // audit.
  return successEnvelope(
    settings,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
