/**
 * organizations-schedule-exceptions — the organization-scoped
 * schedule-exception read Edge Function (Phase 44).
 *
 * THIN DENO ADAPTER: all logic lives in
 * ../_shared/organizations_schedule_exceptions.ts (pure, dependency-free,
 * proven by the local harness). This file only wires the Supabase runtime.
 * It is NOT executed locally — no Deno/Supabase runtime exists in this
 * environment (see supabase/README.md, "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (ScheduleController
 * ::exceptions + AccessCheck::organization parity):
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
 *   3. the exceptions SELECT is bound to the VERIFIED organization id and
 *      the claims — schedule_exceptions is **TENANT_FACILITY** (NOT
 *      TENANT_FACILITY_BRANCH: schedule_exceptions has NO branch_id
 *      column, so the select policy is `tenant_id = TENANT AND
 *      (facility_id = FACILITY OR FACILITY IS NULL)` — there is NO branch
 *      clause; a branch proposal does NOT narrow this read) — and ordered
 *      by `exception_date` DESC — the exact `->orderByDesc('exception_date')`,
 *      eager-loaded with the staff ref — the exact
 *      `->with('staff:id,full_name')` (LEFT JOIN staff on the composite
 *      tenant/facility/staff FK; staff has NO SoftDeletes — no deleted_at
 *      column — and the composite FK is RESTRICT, so the join always
 *      resolves in a consistent DB). NOTE: unlike the templates read,
 *      `presentException()` does NOT expose the staff reference — the
 *      eager load is a query-level detail only, and the SELECT does not
 *      project the staff columns. **Schedule exceptions are NOT
 *      soft-deletable** — the ScheduleException model has NO SoftDeletes
 *      trait and the table has NO `deleted_at` column ("date-scoped rows
 *      expire naturally — no soft delete"), so there is NO soft-delete
 *      filter to reproduce. The facility filter is applied ONLY when the
 *      caller has a facility claim (the exact `! isPlatform &&
 *      facilityId() !== null` guard — org-level / platform callers see
 *      every facility of the tenant). NO status filter — active AND
 *      cancelled both return (the CHECK-constrained lifecycle statuses).
 *      The controller performs NO partial select, so `facility_id` (NOT
 *      NULL in the base schema) and `staff_id` (NOT NULL) are selected and
 *      HYDRATED — both are contract-explicit presented fields carrying
 *      real values; `exception_date` is a DATE column (`YYYY-MM-DD` via
 *      the date cast's toDateString); `reason` ∈ leave/holiday/block (the
 *      CHECK constraint); `status` ∈ active/cancelled (the CHECK
 *      constraint); the `template_id` column EXISTS but is never presented
 *      (and is NOT selected);
 *   4. NO audit — ScheduleController::exceptions records no audit event
 *      (`schedule.exception.created` is a write-side event only).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleOrganizationsScheduleExceptions } from '../_shared/organizations_schedule_exceptions.ts';
import type { OrganizationsScheduleExceptionsDeps, OrganizationScheduleExceptionsResult, ScheduleExceptionRow } from '../_shared/organizations_schedule_exceptions.ts';
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

const deps: OrganizationsScheduleExceptionsDeps = {
  ...identityDeps,

  listOrganizationScheduleExceptions: (claims: Claims, organizationId: string): OrganizationScheduleExceptionsResult => {
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

    // schedule_exceptions is TENANT_FACILITY — the claims scope the read;
    // the facility filter applies ONLY when the caller has a facility claim
    // (the exact Laravel guard); ordered by exception_date DESC (the exact
    // Laravel order); the staff eager load (`with('staff:id,full_name')`)
    // is a query-level detail — the SELECT does NOT project the staff
    // columns because presentException() does not expose the staff
    // reference. **Schedule exceptions are NOT soft-deletable** — no
    // SoftDeletes trait, no `deleted_at` column — so there is NO
    // soft-delete filter. NO status filter — active AND cancelled both
    // return (the CHECK-constrained lifecycle statuses). Only the
    // presented columns are selected — tenant/audit/timestamp metadata
    // never leaves the function (the `template_id` column exists but is
    // never presented and is NOT selected). `facility_id`/`staff_id` are
    // NOT NULL (base schema) and selected + hydrated (`facilityId`/
    // `staffId` carry real values); `exception_date` is a DATE column
    // (`YYYY-MM-DD`); `reason` ∈ leave/holiday/block (CHECK); `status` ∈
    // active/cancelled (CHECK).
    const facilityClause = claims.app_facility_id === ''
      ? ''
      : ' and t.facility_id = $2';
    const params: unknown[] = [organizationId];
    if (facilityClause !== '') params.push(claims.app_facility_id);

    const rows = db.queryObject<ScheduleExceptionDbRow>(
      `select t.id, t.facility_id, t.staff_id, t.exception_date, t.reason, t.status
         from public.schedule_exceptions t
        where t.tenant_id = $1${facilityClause}
        order by t.exception_date desc`,
      params,
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      facilityId: row.facility_id,
      staffId: row.staff_id,
      exceptionDate: row.exception_date,
      reason: row.reason,
      status: row.status,
    } satisfies ScheduleExceptionRow));
  },
};

Deno.serve((req) => handleOrganizationsScheduleExceptions(req, deps));

/* ------------------------------------------------------------------ */

interface ScheduleExceptionDbRow {
  id: string;
  facility_id: string;
  staff_id: string;
  exception_date: string;
  reason: string;
  status: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'organizations-schedule-exceptions wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
