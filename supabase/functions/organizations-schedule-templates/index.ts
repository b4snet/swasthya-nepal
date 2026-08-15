/**
 * organizations-schedule-templates — the organization-scoped schedule-template
 * read Edge Function (Phase 43).
 *
 * THIN DENO ADAPTER: all logic lives in
 * ../_shared/organizations_schedule_templates.ts (pure, dependency-free,
 * proven by the local harness). This file only wires the Supabase runtime.
 * It is NOT executed locally — no Deno/Supabase runtime exists in this
 * environment (see supabase/README.md, "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (ScheduleController
 * ::templates + AccessCheck::organization parity):
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
 *   3. the templates SELECT is bound to the VERIFIED organization id and
 *      the claims — schedule_templates is **TENANT_FACILITY** (NOT
 *      TENANT_FACILITY_BRANCH: schedule_templates has NO branch_id column,
 *      so the select policy is `tenant_id = TENANT AND (facility_id =
 *      FACILITY OR FACILITY IS NULL)` — there is NO branch clause; a
 *      branch proposal does NOT narrow this read) — and ordered by
 *      `day_of_week` ASC — the exact `->orderBy('day_of_week')`,
 *      eager-loaded with the staff ref — the exact
 *      `->with('staff:id,full_name,designation')` (LEFT JOIN staff on the
 *      composite tenant/facility/staff FK — staff has NO SoftDeletes and
 *      the composite FK is RESTRICT, so the ref always resolves in a
 *      consistent DB — the Laravel `?: null` is unreachable in practice).
 *      **Schedule templates ARE
 *      soft-deletable** — the Laravel SoftDeletes model scope excludes
 *      soft-deleted rows, so the SELECT has `t.deleted_at is null` exactly
 *      as the model's default scope does. The facility filter is applied
 *      ONLY when the caller has a facility claim (the exact `! isPlatform
 *      && facilityId() !== null` guard — org-level / platform callers see
 *      every facility of the tenant). NO status filter — active AND
 *      inactive both return (the catalog statuses). The controller performs
 *      NO partial select, so `facility_id` (NOT NULL in the base schema) is
 *      selected and HYDRATED — `facilityId` is a contract-explicit
 *      presented field carrying the real value; `service_id` is NULLABLE
 *      (the composite FK allows NULL — a service-less template);
 *      `starts_at`/`ends_at` are TIME columns formatted `H:i` (the
 *      datetime cast's format); `valid_from`/`valid_to` are DATE columns
 *      (`YYYY-MM-DD`; valid_to nullable); `day_of_week` ∈ 0..6 (ISO 8601);
 *      `slot_minutes`/`capacity` integers;
 *   4. NO audit — ScheduleController::templates records no audit event
 *      (`schedule.template.created` is a write-side event only).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleOrganizationsScheduleTemplates } from '../_shared/organizations_schedule_templates.ts';
import type { OrganizationsScheduleTemplatesDeps, OrganizationScheduleTemplatesResult, ScheduleTemplateRow, ScheduleTemplateStaffRef } from '../_shared/organizations_schedule_templates.ts';
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

const deps: OrganizationsScheduleTemplatesDeps = {
  ...identityDeps,

  listOrganizationScheduleTemplates: (claims: Claims, organizationId: string): OrganizationScheduleTemplatesResult => {
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

    // schedule_templates is TENANT_FACILITY — the claims scope the read;
    // the facility filter applies ONLY when the caller has a facility claim
    // (the exact Laravel guard); ordered by day_of_week ASC (the exact
    // Laravel order); the staff ref is eager-loaded (the exact
    // `with('staff:id,full_name,designation')` — LEFT JOIN on the
    // composite tenant/facility/staff FK; staff has NO SoftDeletes and the
    // composite FK is RESTRICT, so the ref always resolves in a consistent
    // DB — the Laravel `?: null` is unreachable in practice). **Schedule
    // templates ARE soft-deletable** — the Laravel SoftDeletes model scope
    // excludes soft-deleted rows, so `t.deleted_at is null` is the exact
    // model default-scope parity. NO status filter — active AND inactive
    // both return (the catalog statuses). Only the presented columns are
    // selected — tenant/audit/timestamp metadata never leaves the
    // function. `facility_id` is NOT NULL (base schema) and selected +
    // hydrated (`facilityId` carries the real value); `staff_id` NOT NULL
    // with the eager ref; `service_id` NULLABLE (the composite FK allows
    // NULL — a service-less template); `starts_at`/`ends_at` are TIME
    // columns formatted `H:i`; `valid_from`/`valid_to` are DATE columns
    // (`YYYY-MM-DD`, valid_to nullable); `day_of_week` ∈ 0..6 (ISO 8601);
    // `slot_minutes`/`capacity` integers.
    const facilityClause = claims.app_facility_id === ''
      ? ''
      : ' and t.facility_id = $2';
    const params: unknown[] = [organizationId];
    if (facilityClause !== '') params.push(claims.app_facility_id);

    const rows = db.queryObject<ScheduleTemplateDbRow>(
      `select t.id, t.facility_id, t.staff_id, t.service_id,
              t.day_of_week, t.starts_at, t.ends_at,
              t.slot_minutes, t.capacity, t.valid_from, t.valid_to, t.status,
              s.id as staff_id_ref, s.full_name as staff_full_name, s.designation as staff_designation
         from public.schedule_templates t
         left join public.staff s
           on s.tenant_id = t.tenant_id and s.facility_id = t.facility_id and s.id = t.staff_id and s.deleted_at is null
        where t.tenant_id = $1 and t.deleted_at is null${facilityClause}
        order by t.day_of_week asc`,
      params,
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      facilityId: row.facility_id,
      staffId: row.staff_id,
      staff: row.staff_id_ref === null ? null : { id: row.staff_id_ref, fullName: row.staff_full_name, designation: row.staff_designation } as ScheduleTemplateStaffRef,
      serviceId: row.service_id,
      dayOfWeek: row.day_of_week,
      startsAt: row.starts_at.slice(0, 5),
      endsAt: row.ends_at.slice(0, 5),
      slotMinutes: row.slot_minutes,
      capacity: row.capacity,
      validFrom: row.valid_from,
      validTo: row.valid_to,
      status: row.status,
    } satisfies ScheduleTemplateRow));
  },
};

Deno.serve((req) => handleOrganizationsScheduleTemplates(req, deps));

/* ------------------------------------------------------------------ */

interface ScheduleTemplateDbRow {
  id: string;
  facility_id: string;
  staff_id: string;
  staff_id_ref: string | null;
  staff_full_name: string | null;
  staff_designation: string | null;
  service_id: string | null;
  day_of_week: number;
  starts_at: string;
  ends_at: string;
  slot_minutes: number;
  capacity: number;
  valid_from: string;
  valid_to: string | null;
  status: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'organizations-schedule-templates wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
