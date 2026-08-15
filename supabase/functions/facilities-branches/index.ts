/**
 * facilities-branches — the facility-scoped branch read Edge Function
 * (Phase 34).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/facilities_branches.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (BranchController
 * ::index + AccessCheck::facility parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the facility gate SELECT runs as swasthya_app: a nonexistent
 *      facility → 'facility-not-found' → the 404 **'Facility not found.'**
 *      (AccessCheck::facility's own ApiException); a facility outside the
 *      authoritative scope — another tenant, or a facility-scoped
 *      principal requesting another facility (the organization id of the
 *      facility IS its tenant; platform callers bypass the scope check) →
 *      null → the 404 'Resource not found.' (deny(read) — existence is
 *      never leaked). The facility id is a resource selector, never
 *      authorization scope;
 *   3. the branches SELECT is bound to the VERIFIED facility id and the
 *      claims (branches is TENANT_ONLY — the select policy is `tenant_id =
 *      TENANT`; the FACILITY scoping is the query, not RLS — the exact
 *      `->where('facility_id', $facility->getKey())`) and ordered by name
 *      ASC — the exact `->orderBy('name')`. NO status filter — active AND
 *      inactive branches all return (the lifecycle statuses). The exact
 *      Laravel projection selects ONLY id/name/code/status — `facility_id`
 *      is deliberately NOT selected: `present()` reads an un-hydrated
 *      attribute, so **`facilityId` renders null** in every index item (the
 *      literal Laravel index output — the store/show routes hydrate the
 *      full model and return the real facility id);
 *   4. NO audit — BranchController::index records no audit event
 *      (`branch.created` / `branch.updated` / `branch.deleted` are
 *      write-side only).
 *
 * No client-supplied tenant/facility value ever becomes authoritative; no
 * SECURITY DEFINER; no service-role credentials.
 */
import { handleFacilitiesBranches } from '../_shared/facilities_branches.ts';
import type { BranchRow, FacilitiesBranchesDeps, FacilityBranchesResult } from '../_shared/facilities_branches.ts';
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

const deps: FacilitiesBranchesDeps = {
  ...identityDeps,

  listFacilityBranches: (claims: Claims, facilityId: string): FacilityBranchesResult => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // The facility gate: nonexistent → 'facility-not-found' (the
    // AccessCheck::facility 404 'Facility not found.'); out-of-scope (a
    // facility of another tenant, or a facility-scoped principal
    // requesting another facility) → null (deny(read), 404 'Resource not
    // found.'). Platform callers bypass the scope check.
    const facility = db.queryObject<{ id: string; tenant_id: string }>(
      'select id, tenant_id from public.facilities where id = $1 limit 1',
      [facilityId],
    ).rows[0];
    if (facility === undefined) return 'facility-not-found';
    if (
      claims.app_is_platform !== 'true'
      && (claims.app_tenant_id !== facility.tenant_id
        || (claims.app_facility_id !== '' && claims.app_facility_id !== facilityId))
    ) {
      return null;
    }

    // branches is TENANT_ONLY — the tenant claim + the VERIFIED facility
    // binding scope the read (the facility scoping is the query, not RLS —
    // the exact Laravel `->where('facility_id', ...)`); ordered by name
    // ASC (the exact Laravel order). NO status filter — active AND
    // inactive return. Soft-deleted rows are excluded (deleted_at is
    // null). The exact Laravel index projection selects ONLY
    // id/name/code/status — `facility_id` is deliberately NOT selected and
    // `facilityId` renders null (the literal Laravel index output);
    // tenant/audit/timestamp metadata never leaves the function.
    const rows = db.queryObject<BranchDbRow>(
      `select id, name, code, status
         from public.branches
        where tenant_id = $1 and facility_id = $2 and deleted_at is null
        order by name asc`,
      [facility.tenant_id, facilityId],
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      facilityId: null,
      name: row.name,
      code: row.code,
      status: row.status,
    }));
  },
};

Deno.serve((req) => handleFacilitiesBranches(req, deps));

/* ------------------------------------------------------------------ */

interface BranchDbRow {
  id: string;
  name: string;
  code: string;
  status: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'facilities-branches wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
