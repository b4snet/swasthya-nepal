/**
 * appointments-show — the single-appointment READ Edge Function (Phase 21).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/appointments_show.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (AppointmentController
 * ::show + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the appointment SELECT by id runs as swasthya_app — the claims-scoped
 *      appointments policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth only — the id is a resource selector, never
 *      authorization scope;
 *   3. the patient and provider refs resolve under the SAME claims
 *      (patients and staff are both TENANT_FACILITY claims-scoped tables) —
 *      a related row outside the caller's scope renders null in the
 *      presentation, never an error and never a leak;
 *   4. NO audit — AppointmentController::show records no audit event; this
 *      read is a pure SELECT (no mutation, no audit chain).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleAppointmentsShow } from '../_shared/appointments_show.ts';
import type { AppointmentsShowDeps } from '../_shared/appointments_show.ts';
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

const deps: AppointmentsShowDeps = {
  ...identityDeps,

  findAppointmentByScope: (claims: Claims, id: string): AppointmentRow | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<AppointmentDbRow>(
      `select id, facility_id, patient_id, provider_staff_id, service_id,
              appointment_type, starts_at::text, ends_at::text, status, token_no,
              source, cancel_reason, lock_version, created_at::text, updated_at::text
         from public.appointments
        where id = $3 and tenant_id = $1 and facility_id = $2 limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    return row ? mapAppointment(row) : null;
  },

  findPatientByScope: (claims: Claims, patientId: string) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<{ id: string; mrn: string; full_name: string }>(
      `select id, mrn, full_name from public.patients
        where id = $3 and tenant_id = $1 and facility_id = $2 limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, patientId],
    ).rows[0];
    return row ? { id: row.id, mrn: row.mrn, fullName: row.full_name } : null;
  },

  findProviderByScope: (claims: Claims, providerStaffId: string) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<{ id: string; facility_id: string; full_name: string }>(
      `select id, facility_id, full_name from public.staff
        where id = $3 and tenant_id = $1 and facility_id = $2 and status <> 'departed' limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, providerStaffId],
    ).rows[0];
    return row ? { id: row.id, facilityId: row.facility_id, fullName: row.full_name } : null;
  },
};

Deno.serve((req) => handleAppointmentsShow(req, deps));

/* ------------------------------------------------------------------ */

interface AppointmentDbRow {
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
}

function mapAppointment(row: AppointmentDbRow): AppointmentRow {
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
    'appointments-show wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
