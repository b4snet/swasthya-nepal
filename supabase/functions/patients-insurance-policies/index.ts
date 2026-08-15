/**
 * patients-insurance-policies — the patient-scoped insurance-policy read
 * Edge Function (Phase 30).
 *
 * THIN DENO ADAPTER: all logic lives in
 * ../_shared/patients_insurance_policies.ts (pure, dependency-free, proven
 * by the local harness). This file only wires the Supabase runtime. It is
 * NOT executed locally — no Deno/Supabase runtime exists in this
 * environment (see supabase/README.md, "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (InsurancePolicy
 * Controller::index + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the patient gate SELECT runs as swasthya_app — the claims-scoped
 *      patients policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth — the id is a resource selector, never
 *      authorization scope;
 *   3. the insurance_policies SELECT is bound to the VERIFIED patient id
 *      and the tenant claim (insurance_policies is a TENANT_ONLY
 *      claims-scoped table — the patient gate already scoped the caller to
 *      this facility) and ordered by created_at DESC — the exact
 *      `->orderByDesc('created_at')`. NO status filter — active, expired
 *      AND cancelled policies all return (status is a lifecycle, never a
 *      deletion). The payer ref LEFT-JOINs under the SAME tenant claim
 *      (payers is TENANT_ONLY — the eager `payer:id,name,code` parity) —
 *      an out-of-scope payer row joins to NULL, never a leak. `benefits`
 *      is the decoded jsonb payload (the 'array' cast); `valid_from` is a
 *      date string (the column is NOT NULL), `valid_to` nullable;
 *      `patientId`/`payerId`/`lockVersion` are contract-explicit presented
 *      fields (the exact Laravel map);
 *   4. NO audit — InsurancePolicyController::index records no audit event
 *      (`patient.insurance.added/updated/cancelled` are write-side only).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handlePatientsInsurancePolicies } from '../_shared/patients_insurance_policies.ts';
import type { InsurancePolicyRow, PatientsInsurancePoliciesDeps } from '../_shared/patients_insurance_policies.ts';
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

const deps: PatientsInsurancePoliciesDeps = {
  ...identityDeps,

  listPatientInsurancePolicies: (claims: Claims, id: string): InsurancePolicyRow[] | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // The 404 gate: the patient itself must be visible under the claims
    // (TENANT_FACILITY). Out-of-scope ≡ nonexistent → null.
    const patient = db.queryObject<{ id: string }>(
      'select id from public.patients where id = $3 and tenant_id = $1 and facility_id = $2 limit 1',
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    if (patient === undefined) return null;

    // insurance_policies is TENANT_ONLY — the tenant claim + the verified
    // patient binding scope the read; ordered by created_at DESC (the exact
    // Laravel order). NO status filter. The payer ref LEFT-JOINs under the
    // same tenant claim (payers is TENANT_ONLY — the eager
    // `payer:id,name,code` parity); an out-of-scope payer renders NULL,
    // never a leak. `benefits` is the decoded jsonb; `valid_from`/`valid_to`
    // are date strings.
    const rows = db.queryObject<InsurancePolicyDbRow>(
      `select p.id, p.patient_id, p.payer_id, p.policy_number, p.coverage_type,
              p.valid_from::text, p.valid_to::text, p.benefits, p.status, p.lock_version,
              py.id as payer_ref_id, py.name as payer_ref_name, py.code as payer_ref_code
         from public.insurance_policies p
         left join public.payers py on py.id = p.payer_id and py.tenant_id = p.tenant_id
        where p.patient_id = $2 and p.tenant_id = $1
        order by p.created_at desc`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, id],
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      patientId: row.patient_id,
      payerId: row.payer_id,
      payer: row.payer_ref_id === null ? null : { id: row.payer_ref_id, name: row.payer_ref_name, code: row.payer_ref_code },
      policyNumber: row.policy_number,
      coverageType: row.coverage_type,
      validFrom: row.valid_from,
      validTo: row.valid_to,
      benefits: row.benefits,
      status: row.status,
      lockVersion: row.lock_version,
    }));
  },
};

Deno.serve((req) => handlePatientsInsurancePolicies(req, deps));

/* ------------------------------------------------------------------ */

interface InsurancePolicyDbRow {
  id: string;
  patient_id: string;
  payer_id: string;
  policy_number: string;
  coverage_type: string;
  valid_from: string;
  valid_to: string | null;
  benefits: Record<string, unknown> | unknown[];
  status: string;
  lock_version: number;
  payer_ref_id: string | null;
  payer_ref_name: string | null;
  payer_ref_code: string | null;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'patients-insurance-policies wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
