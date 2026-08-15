/**
 * organizations-rooms — the organization-scoped room read Edge Function
 * (Phase 37).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/organizations_rooms.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (RoomController
 * ::index + AccessCheck::organization parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the organization gate SELECT runs as swasthya_app: a nonexistent
 *      organization → 'organization-not-found' → the 404 **'Organization
 *      not found.'** (AccessCheck::organization's own ApiException); an
 *      organization outside the authoritative tenant claim (no assignment
 *      whose tenant_id equals the organization key — the organization id
 *      IS the tenant id) → null → the 404 'Resource not found.'
 *      (deny(read) — existence is never leaked). Platform callers bypass
 *      the scope check (AccessCheck::organization); the organization id is
 *      a resource selector, never authorization scope;
 *   3. the rooms SELECT is bound to the VERIFIED organization id and the
 *      claims (rooms is TENANT_FACILITY_BRANCH — the select policy is
 *      `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS
 *      NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS
 *      NULL)`) and ordered by name ASC — the exact `->orderBy('name')`,
 *      eager-loaded with the ward ref — the exact
 *      `->with('ward:id,code,name')` (LEFT JOIN wards on the composite
 *      tenant/facility/ward FK). The facility filter is applied ONLY when
 *      the caller has a facility claim (the exact `! isPlatform &&
 *      facilityId() !== null` guard — org-level / platform callers see
 *      every facility of the tenant). NO status filter — active AND
 *      inactive rooms all return (the lifecycle statuses); the controller
 *      performs NO partial select, so `facility_id`/`ward_id` (NOT NULL in
 *      the base schema) and `branch_id` (nullable — tenancy_v2) are
 *      selected and HYDRATED — `facilityId`/`branchId`/`wardId` are
 *      contract-explicit presented fields carrying the real values;
 *      `daily_rate_minor`/`currency` nullable; the eager ward ref carries
 *      exactly id/code/name;
 *   4. NO audit — RoomController::index records no audit event
 *      (`room.created` / `room.updated` / rate-change events are write-side
 *      only).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleOrganizationsRooms } from '../_shared/organizations_rooms.ts';
import type { OrganizationsRoomsDeps, OrganizationRoomsResult, RoomRow, RoomWardRef } from '../_shared/organizations_rooms.ts';
import type { Claims } from '../_shared/claims.ts';
import type { HealthAuthDeps } from '../_shared/pipeline.ts';

const db = postgresFromEnv(); // deployed wiring — see supabase/README.md

const identityDeps: HealthAuthDeps = {
  secret: Deno.env.get('SUPABASE_JWT_SECRET') ?? '',
  issuer: 'supabase',
  audience: 'authenticated',
  findUserBySubject: (sub) => {
    const row = db.queryObject<{ id: string; email: string | null; status: string }>(
      'select id, email, status from public.users where auth_subject_id = $1 limit 1',
      [sub],
    ).rows[0];
    return row ? { id: row.id, email: row.email ?? undefined, status: row.status as AppUserStatus } : null;
  },
  loadActiveAssignments: (userId) =>
    db.queryObject<AssignmentRow>(
      `select ra.id, ra.user_id, ra.role_id, ra.tenant_id, ra.facility_id, ra.branch_id, ra.scope_type,
              r.code as role_code, r.scope_type as role_scope_type
         from public.role_assignments ra
         join public.roles r on r.id = ra.role_id
        where ra.user_id = $1 and ra.status = 'active'`,
      [userId],
    ).rows.map((row) => ({
      id: row.id,
      userId: row.user_id,
      roleId: row.role_id,
      tenantId: row.tenant_id,
      facilityId: row.facility_id,
      branchId: row.branch_id,
      scopeType: row.scope_type,
      role: { id: row.role_id, code: row.role_code, scopeType: row.role_scope_type, permissions: [] },
    })),
  activeSupportSession: (userId) => {
    const row = db.queryObject<{ id: string; organization_id: string; facility_id: string | null }>(
      `select id, organization_id, facility_id from public.support_sessions
        where user_id = $1 and status = 'active' and expires_at > now()
        order by opened_at desc limit 1`,
      [userId],
    ).rows[0];
    return row ? { id: row.id, organizationId: row.organization_id, facilityId: row.facility_id } : null;
  },
  loadOrganization: (id) => {
    const row = db.queryObject<{ id: string; status: string; timezone: string | null }>(
      'select id, status, timezone from public.organizations where id = $1',
      [id],
    ).rows[0];
    return row ? { id: row.id, status: row.status, timezone: row.timezone ?? undefined } : null;
  },
  loadFacility: (id) => {
    const row = db.queryObject<{ id: string; tenant_id: string; timezone: string | null }>(
      'select id, tenant_id, timezone from public.facilities where id = $1',
      [id],
    ).rows[0];
    return row ? { id: row.id, tenantId: row.tenant_id, timezone: row.timezone ?? undefined } : null;
  },
  loadBranch: (id) => {
    const row = db.queryObject<{ id: string; tenant_id: string; facility_id: string }>(
      'select id, tenant_id, facility_id from public.branches where id = $1',
      [id],
    ).rows[0];
    return row ? { id: row.id, tenantId: row.tenant_id, facilityId: row.facility_id } : null;
  },
};

const deps: OrganizationsRoomsDeps = {
  ...identityDeps,

  listOrganizationRooms: (claims: Claims, organizationId: string): OrganizationRoomsResult => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // The organization gate: nonexistent → 'organization-not-found' (the
    // AccessCheck::organization 404 'Organization not found.'); the id IS
    // the tenant id — out-of-tenant (no assignment with this tenant, and
    // not a platform caller) → null (deny(read), 404 'Resource not
    // found.'). Platform callers bypass the scope check.
    const organization = db.queryObject<{ id: string }>(
      'select id from public.organizations where id = $1 limit 1',
      [organizationId],
    ).rows[0];
    if (organization === undefined) return 'organization-not-found';
    if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;

    // rooms is TENANT_FACILITY_BRANCH — the claims scope the read; the
    // facility filter applies ONLY when the caller has a facility claim
    // (the exact Laravel guard); ordered by name ASC (the exact Laravel
    // order); the ward ref is eager-loaded (the exact
    // `with('ward:id,code,name')` — LEFT JOIN on the composite
    // tenant/facility/ward FK). NO status filter — active AND inactive
    // return. Soft-deleted rows are excluded (deleted_at is null). Only
    // the presented columns are selected — tenant/audit/timestamp metadata
    // never leaves the function. `facility_id`/`ward_id` are NOT NULL
    // (base schema) and `branch_id` is nullable (tenancy_v2) — all three
    // are selected and hydrated (`facilityId`/`branchId`/`wardId` carry
    // the real values); `daily_rate_minor`/`currency` are nullable.
    const facilityClause = claims.app_facility_id === ''
      ? ''
      : ' and r.facility_id = $2';
    const params: unknown[] = [organizationId];
    if (facilityClause !== '') params.push(claims.app_facility_id);

    const rows = db.queryObject<RoomDbRow>(
      `select r.id, r.facility_id, r.branch_id, r.ward_id,
              r.name, r.code, r.room_type, r.daily_rate_minor, r.currency, r.status,
              w.id as ward_id_ref, w.code as ward_code, w.name as ward_name
         from public.rooms r
         left join public.wards w
           on w.tenant_id = r.tenant_id and w.facility_id = r.facility_id and w.id = r.ward_id and w.deleted_at is null
        where r.tenant_id = $1 and r.deleted_at is null${facilityClause}
        order by r.name asc`,
      params,
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      facilityId: row.facility_id,
      branchId: row.branch_id,
      wardId: row.ward_id,
      ward: row.ward_id_ref === null ? null : { id: row.ward_id_ref, code: row.ward_code, name: row.ward_name } as RoomWardRef,
      name: row.name,
      code: row.code,
      roomType: row.room_type,
      dailyRateMinor: row.daily_rate_minor,
      currency: row.currency,
      status: row.status,
    }));
  },
};

Deno.serve((req) => handleOrganizationsRooms(req, deps));

/* ------------------------------------------------------------------ */

interface RoomDbRow {
  id: string;
  facility_id: string;
  branch_id: string | null;
  ward_id: string;
  ward_id_ref: string | null;
  ward_code: string | null;
  ward_name: string | null;
  name: string;
  code: string;
  room_type: string;
  daily_rate_minor: number | null;
  currency: string | null;
  status: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'organizations-rooms wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
