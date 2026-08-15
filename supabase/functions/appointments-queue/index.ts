/**
 * appointments-queue — the live front-desk queue read Edge Function
 * (Phase 27).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/appointments_queue.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (AppointmentController
 * ::queue parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the appointments SELECT runs as swasthya_app — the claims-scoped
 *      appointments policy (TENANT_FACILITY) is the FINAL boundary; the
 *      explicit tenant/facility WHERE is defense-in-depth (facility applies
 *      only when the caller has a facility claim — org-level claims see
 *      every tenant facility, the RLS facilityClause `facility_id = claim
 *      OR claim IS NULL` parity, written null-safe as `coalesce(?, a.facility_id)
 *      = a.facility_id`);
 *   3. the always-applied status IN (checked_in, in_consultation), the
 *      `date(a.starts_at) = date` filter (defaulting to the runtime today
 *      when absent — the exact `$request->query('date', today()->toDateString())`),
 *      the optional providerStaffId exact-match filter, and `order by
 *      token_no asc` (the exact `->orderBy('token_no')`);
 *   4. the patient ref and the encounter id resolve under the same claims
 *      (patient TENANT_FACILITY, encounter TENANT_FACILITY) — an
 *      out-of-scope related row renders NULL, never a leak;
 *   5. NO audit — AppointmentController::queue records no audit event.
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleAppointmentsQueue } from '../_shared/appointments_queue.ts';
import type { AppointmentsQueueDeps, QueueEntryRow } from '../_shared/appointments_queue.ts';
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

const deps: AppointmentsQueueDeps = {
  ...identityDeps,

  // The server-side today (the Laravel `today()->toDateString()` default).
  todayIso: () => new Date().toISOString().slice(0, 10),

  listAppointmentQueue: (claims: Claims, filters: { date: string; providerStaffId?: string }): QueueEntryRow[] => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    const facilityClaim = claims.app_facility_id === '' ? null : claims.app_facility_id;
    const bindings: unknown[] = [claims.app_tenant_id === '' ? null : claims.app_tenant_id, facilityClaim, filters.date];

    let sql = `select a.id, a.token_no, a.status, a.starts_at::text,
                      p.id as patient_id, p.mrn as patient_mrn, p.full_name as patient_full_name,
                      e.id as encounter_id
                 from public.appointments a
                 left join public.patients p
                        on p.id = a.patient_id and p.tenant_id = a.tenant_id
                       and coalesce($2, p.facility_id) = p.facility_id
                 left join public.encounters e
                        on e.appointment_id = a.id and e.tenant_id = a.tenant_id
                       and coalesce($2, e.facility_id) = e.facility_id
                where a.tenant_id = $1
                  and coalesce($2, a.facility_id) = a.facility_id
                  and date(a.starts_at) = $3
                  and a.status in ('checked_in', 'in_consultation')`;

    if (filters.providerStaffId !== undefined) {
      bindings.push(filters.providerStaffId);
      sql += ` and a.provider_staff_id = $${bindings.length}`;
    }

    sql += ' order by a.token_no asc';

    const rows = db.queryObject<QueueDbRow>(sql, bindings).rows;

    return rows.map((row) => ({
      appointmentId: row.id,
      tokenNo: row.token_no,
      status: row.status,
      patient: row.patient_id !== null
        ? { id: row.patient_id, mrn: row.patient_mrn, fullName: row.patient_full_name }
        : null,
      startsAt: row.starts_at,
      encounterId: row.encounter_id,
    }));
  },
};

Deno.serve((req) => handleAppointmentsQueue(req, deps));

/* ------------------------------------------------------------------ */

interface QueueDbRow {
  id: string;
  token_no: number | null;
  status: string;
  starts_at: string | null;
  patient_id: string | null;
  patient_mrn: string | null;
  patient_full_name: string | null;
  encounter_id: string | null;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'appointments-queue wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
