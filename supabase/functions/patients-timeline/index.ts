/**
 * patients-timeline — the patient-scoped timeline read Edge Function
 * (Phase 26).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/patients_timeline.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (PatientController
 * ::timeline + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the patient gate SELECT runs as swasthya_app — the claims-scoped
 *      patients policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth — the id is a resource selector, never
 *      authorization scope;
 *   3. the patient_timeline_entries SELECT is bound to the VERIFIED patient
 *      id and the tenant claim (patient_timeline_entries is a TENANT_ONLY
 *      claims-scoped table — the patient gate already scoped the caller to
 *      this facility) and ordered by occurred_at DESC then id DESC — the
 *      exact `->orderByDesc('occurred_at')->orderByDesc('id')`; `summary`
 *      is the jsonb structured payload (the PatientTimelineEntry 'array'
 *      cast), never clinical content per the timeline no-PHI rule;
 *   4. NO audit — PatientController::timeline records no audit event.
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handlePatientsTimeline } from '../_shared/patients_timeline.ts';
import type { PatientsTimelineDeps, TimelineEntryRow } from '../_shared/patients_timeline.ts';
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

const deps: PatientsTimelineDeps = {
  ...identityDeps,

  listPatientTimeline: (claims: Claims, id: string): TimelineEntryRow[] | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // The 404 gate: the patient itself must be visible under the claims
    // (TENANT_FACILITY). Out-of-scope ≡ nonexistent → null.
    const patient = db.queryObject<{ id: string }>(
      'select id from public.patients where id = $3 and tenant_id = $1 and facility_id = $2 limit 1',
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    if (patient === undefined) return null;

    // patient_timeline_entries is TENANT_ONLY — the tenant claim + the
    // verified patient binding scope the read; ordered by occurred_at DESC
    // then id DESC (the exact Laravel order). `summary` is the decoded jsonb.
    const rows = db.queryObject<PatientTimelineDbRow>(
      `select id, occurred_at::text, event_type, summary
         from public.patient_timeline_entries
        where patient_id = $2 and tenant_id = $1
        order by occurred_at desc, id desc`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, id],
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      occurredAt: row.occurred_at,
      eventType: row.event_type,
      summary: row.summary,
    }));
  },
};

Deno.serve((req) => handlePatientsTimeline(req, deps));

/* ------------------------------------------------------------------ */

interface PatientTimelineDbRow {
  id: string;
  occurred_at: string | null;
  event_type: string;
  summary: Record<string, unknown>;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'patients-timeline wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
