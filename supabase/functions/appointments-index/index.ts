/**
 * appointments-index — the claims-scoped appointment LIST Edge Function
 * (Phase 22).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/appointments_index.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped list is the production-critical wiring (AppointmentController
 * ::index + the claims boundary parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the appointment SELECT runs as swasthya_app — the claims-scoped
 *      appointments policy is the FINAL boundary. The explicit scope WHERE
 *      mirrors the RLS facilityClause exactly (`facility_id = claim OR
 *      claim IS NULL`): a facility claim narrows to one facility, an
 *      org-level claim (facility NULL) sees every facility of the tenant;
 *   3. the patient/provider REFS resolve in the SAME query under the SAME
 *      claims via LEFT JOINs (mirror of the eager-loaded
 *      `patient:id,mrn,full_name` / `provider:id,full_name` relations) — a
 *      related row outside the caller's scope joins to NULL and renders
 *      null, never a leak;
 *   4. the `date` / `providerStaffId` filters and `order by starts_at asc`
 *      reproduce the Laravel query exactly; the pure handler has already
 *      rejected malformed filter values (Laravel's PG-cast 500 parity);
 *   5. NO audit — AppointmentController::index records no audit event.
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleAppointmentsIndex } from '../_shared/appointments_index.ts';
import type { AppointmentsIndexDeps, AppointmentsIndexFilters } from '../_shared/appointments_index.ts';
import type { AppointmentRow } from '../_shared/appointments_create.ts';
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

const deps: AppointmentsIndexDeps = {
  ...identityDeps,

  listAppointments: (claims: Claims, filters: AppointmentsIndexFilters) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const params: unknown[] = [
      claims.app_tenant_id === '' ? null : claims.app_tenant_id,
      claims.app_facility_id === '' ? null : claims.app_facility_id,
    ];
    // The scope WHERE mirrors the RLS facilityClause exactly — defense in
    // depth; the claims-scoped policy is the final boundary. `coalesce($2,
    // x.facility_id) = x.facility_id` is the null-safe spelling that ALSO
    // gives PG a uuid type for the parameter (a bare `$2 is null` cannot be
    // type-inferred — SQLSTATE 42P18).
    let sql = `select a.id, a.facility_id, a.patient_id, a.provider_staff_id, a.service_id,
                      a.appointment_type, a.starts_at::text, a.ends_at::text, a.status, a.token_no,
                      a.source, a.cancel_reason, a.lock_version, a.created_at::text, a.updated_at::text,
                      p.id as patient_ref_id, p.mrn as patient_ref_mrn, p.full_name as patient_ref_full_name,
                      s.id as provider_ref_id, s.full_name as provider_ref_full_name,
                      s.facility_id as provider_ref_facility_id
                 from public.appointments a
                 left join public.patients p
                        on p.id = a.patient_id and p.tenant_id = a.tenant_id
                       and coalesce($2, p.facility_id) = p.facility_id
                 left join public.staff s
                        on s.id = a.provider_staff_id and s.tenant_id = a.tenant_id
                       and coalesce($2, s.facility_id) = s.facility_id and s.status <> 'departed'
                where a.tenant_id = $1 and coalesce($2, a.facility_id) = a.facility_id`;
    if (filters.date !== undefined) {
      params.push(filters.date);
      sql += ` and date(a.starts_at) = $${params.length}`;
    }
    if (filters.providerStaffId !== undefined) {
      params.push(filters.providerStaffId);
      sql += ` and a.provider_staff_id = $${params.length}`;
    }
    sql += ' order by a.starts_at asc';

    return db.queryObject<AppointmentIndexDbRow>(sql, params).rows.map((row) => ({
      appointment: mapAppointment(row),
      patient: row.patient_ref_id !== null
        ? { id: row.patient_ref_id, mrn: row.patient_ref_mrn, fullName: row.patient_ref_full_name }
        : null,
      provider: row.provider_ref_id !== null
        ? { id: row.provider_ref_id, fullName: row.provider_ref_full_name, facilityId: row.provider_ref_facility_id }
        : null,
    }));
  },
};

Deno.serve((req) => handleAppointmentsIndex(req, deps));

/* ------------------------------------------------------------------ */

interface AppointmentIndexDbRow {
  id: string;
  facility_id: string;
  patient_id: string;
  provider_staff_id: string;
  service_id: string | null;
  appointment_type: string;
  starts_at: string;
  ends_at: string;
  status: string;
  token_no: number | null;
  source: string;
  cancel_reason: string | null;
  lock_version: number;
  created_at: string | null;
  updated_at: string | null;
  patient_ref_id: string | null;
  patient_ref_mrn: string | null;
  patient_ref_full_name: string | null;
  provider_ref_id: string | null;
  provider_ref_full_name: string | null;
  provider_ref_facility_id: string | null;
}

function mapAppointment(row: AppointmentIndexDbRow): AppointmentRow {
  return {
    id: row.id,
    facilityId: row.facility_id,
    patientId: row.patient_id,
    providerStaffId: row.provider_staff_id,
    serviceId: row.service_id,
    appointmentType: row.appointment_type,
    startsAt: row.starts_at,
    endsAt: row.ends_at,
    status: row.status,
    tokenNo: row.token_no,
    source: row.source,
    cancelReason: row.cancel_reason,
    lockVersion: row.lock_version,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
  transaction(): {
    execute(sql: string, params?: unknown[]): void;
    queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
    commit(): void;
    rollback(): void;
  };
} {
  throw new Error(
    'appointments-index wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
