/**
 * organizations-services — the organization-scoped service-catalog read Edge
 * Function (Phase 40).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/organizations_services.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (ServiceController
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
 *   3. the services SELECT is bound to the VERIFIED organization id and
 *      the claims — services is **TENANT_FACILITY** (NOT
 *      TENANT_FACILITY_BRANCH: services has NO branch_id column, so the
 *      select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
 *      FACILITY IS NULL)` — there is NO branch clause; a branch proposal
 *      does NOT narrow this read) — and ordered by `name` ASC — the exact
 *      `->orderBy('name')`, eager-loaded with the department ref — the
 *      exact `->with('department:id,code,name')` (LEFT JOIN departments on
 *      the composite tenant/facility/department FK with the soft-delete
 *      guard — a soft-deleted department renders the ref null, never a
 *      leak). **Services ARE soft-deletable** (unlike staff/beds) — the
 *      Laravel SoftDeletes model scope excludes soft-deleted rows, so the
 *      SELECT has `s.deleted_at is null` exactly as the model's default
 *      scope does. The facility filter is applied ONLY when the caller has
 *      a facility claim (the exact `! isPlatform && facilityId() !== null`
 *      guard — org-level / platform callers see every facility of the
 *      tenant). NO status filter — active AND inactive both return (the
 *      catalog statuses). The controller performs NO partial select, so
 *      `facility_id` (NOT NULL in the base schema) is selected and
 *      HYDRATED — `facilityId` is a contract-explicit presented field
 *      carrying the real value; `department_id`/`default_duration_minutes`/
 *      `default_charge_minor`/`currency` are NULLABLE — `departmentId` is
 *      contract-explicit and presented as null when unset; money is integer
 *      minor units (never floats — DATABASE.md §0.4);
 *   4. NO audit — ServiceController::index records no audit event
 *      (`service.created` / `service.updated` / `service.deleted` are
 *      write-side events only).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleOrganizationsServices } from '../_shared/organizations_services.ts';
import type { OrganizationsServicesDeps, OrganizationServicesResult, ServiceRow, ServiceDepartmentRef } from '../_shared/organizations_services.ts';
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

const deps: OrganizationsServicesDeps = {
  ...identityDeps,

  listOrganizationServices: (claims: Claims, organizationId: string): OrganizationServicesResult => {
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

    // services is TENANT_FACILITY — the claims scope the read; the facility
    // filter applies ONLY when the caller has a facility claim (the exact
    // Laravel guard); ordered by name ASC (the exact Laravel order); the
    // department ref is eager-loaded (the exact
    // `with('department:id,code,name')` — LEFT JOIN on the composite
    // tenant/facility/department FK with the soft-delete guard — a
    // soft-deleted department renders the ref null). **Services ARE
    // soft-deletable** — the Laravel SoftDeletes model scope excludes
    // soft-deleted rows, so `s.deleted_at is null` is the exact model
    // default-scope parity. NO status filter — active AND inactive both
    // return (the catalog statuses). Only the presented columns are
    // selected — tenant/audit/timestamp metadata never leaves the
    // function. `facility_id` is NOT NULL (base schema) and selected +
    // hydrated (`facilityId` carries the real value); `department_id`/
    // `default_duration_minutes`/`default_charge_minor`/`currency` are
    // NULLABLE — `departmentId` is contract-explicit and presented as null
    // when unset; money is integer minor units.
    const facilityClause = claims.app_facility_id === ''
      ? ''
      : ' and s.facility_id = $2';
    const params: unknown[] = [organizationId];
    if (facilityClause !== '') params.push(claims.app_facility_id);

    const rows = db.queryObject<ServiceDbRow>(
      `select s.id, s.facility_id, s.department_id,
              s.name, s.code, s.service_type, s.status,
              s.default_duration_minutes, s.default_charge_minor, s.currency,
              d.id as dept_id_ref, d.code as dept_code, d.name as dept_name
         from public.services s
         left join public.departments d
           on d.tenant_id = s.tenant_id and d.facility_id = s.facility_id and d.id = s.department_id and d.deleted_at is null
        where s.tenant_id = $1 and s.deleted_at is null${facilityClause}
        order by s.name asc`,
      params,
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      facilityId: row.facility_id,
      departmentId: row.department_id,
      department: row.dept_id_ref === null ? null : { id: row.dept_id_ref, code: row.dept_code, name: row.dept_name } as ServiceDepartmentRef,
      name: row.name,
      code: row.code,
      serviceType: row.service_type,
      status: row.status,
      defaultDurationMinutes: row.default_duration_minutes,
      defaultChargeMinor: row.default_charge_minor,
      currency: row.currency,
    }));
  },
};

Deno.serve((req) => handleOrganizationsServices(req, deps));

/* ------------------------------------------------------------------ */

interface ServiceDbRow {
  id: string;
  facility_id: string;
  department_id: string | null;
  dept_id_ref: string | null;
  dept_code: string | null;
  dept_name: string | null;
  name: string;
  code: string;
  service_type: string;
  status: string;
  default_duration_minutes: number | null;
  default_charge_minor: number | null;
  currency: string | null;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'organizations-services wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
