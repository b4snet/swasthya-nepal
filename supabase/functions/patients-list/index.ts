/**
 * patients-list — the first real read-only domain Edge Function.
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/patients_list.ts (pure,
 * dependency-free, proven by the local harness). This file only wires the
 * Supabase runtime. It is NOT executed locally — no Deno/Supabase runtime
 * exists in this environment (see supabase/README.md, "validation tiers").
 *
 * The RLS-scoped query is the production-critical wiring:
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the SELECT runs as swasthya_app — the p_rls_patients_select policy
 *      (tenant_id = claims tenant AND facility_id = claims facility) is the
 *      FINAL boundary;
 *   3. no client-supplied tenant/facility/branch value ever reaches the
 *      WHERE clause.
 */
import { handlePatientsList } from '../_shared/patients_list.ts';
import type { PatientsListDeps } from '../_shared/patients_list.ts';
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

const deps: PatientsListDeps = {
  ...identityDeps,
  listPatients: (claims: Claims) => {
    // The claims are server-derived (the pipeline ignores the client). Set
    // the GUC, then let RLS decide visibility — the explicit WHERE mirrors
    // the Laravel index() scoping and is defense-in-depth, never the
    // security decision.
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const rows = db.queryObject<PatientDbRow>(
      `select id, mrn, facility_id, full_name, date_of_birth, sex, blood_group,
              status, created_at, updated_at
         from public.patients
        where tenant_id = $1 and facility_id = $2
        order by created_at desc`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id],
    ).rows;
    return rows.map((row) => ({
      id: row.id,
      mrn: row.mrn,
      facilityId: row.facility_id,
      fullName: row.full_name,
      dateOfBirth: row.date_of_birth,
      sex: row.sex,
      bloodGroup: row.blood_group,
      status: row.status,
      createdAt: row.created_at,
      updatedAt: row.updated_at,
    }));
  },
};

Deno.serve((req) => handlePatientsList(req, deps));

/* ------------------------------------------------------------------ */
/* Deployed-only wiring helpers (types + driver bootstrap)             */
/* ------------------------------------------------------------------ */

type AppUserStatus = 'pending' | 'active' | 'locked' | 'disabled';

interface AssignmentRow {
  id: string;
  user_id: string;
  role_id: string;
  tenant_id: string | null;
  facility_id: string | null;
  branch_id: string | null;
  scope_type: string;
  role_code: string;
  role_scope_type: 'platform' | 'organization' | 'facility';
}

interface PatientDbRow {
  id: string;
  mrn: string;
  facility_id: string;
  full_name: string;
  date_of_birth: string | null;
  sex: string | null;
  blood_group: string | null;
  status: string;
  created_at: string | null;
  updated_at: string | null;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'patients-list wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
