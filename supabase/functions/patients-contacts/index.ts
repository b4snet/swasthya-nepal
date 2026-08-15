/**
 * patients-contacts — the patient-scoped contact read Edge Function
 * (Phase 29).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/patients_contacts.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (PatientContact
 * Controller::index + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the patient gate SELECT runs as swasthya_app — the claims-scoped
 *      patients policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth — the id is a resource selector, never
 *      authorization scope;
 *   3. the patient_contacts SELECT is bound to the VERIFIED patient id and
 *      the tenant claim (patient_contacts is a TENANT_ONLY claims-scoped
 *      table — the patient gate already scoped the caller to this
 *      facility) and ordered by is_primary DESC then created_at ASC — the
 *      exact `->orderByDesc('is_primary')->orderBy('created_at')`
 *      (boolean DESC → primary first; ASC default NULLS LAST on the
 *      secondary key). NO status filter — active AND superseded contacts
 *      both return (the Laravel query has none). `address` and
 *      `contact_person` are the decoded jsonb structured payloads (the
 *      PatientContact 'array' casts); `value` is the plain nullable text
 *      (phone/email/emergency phone — NOT encrypted; no crypto boundary);
 *   4. NO audit — PatientContactController::index records no audit event
 *      (`patient.contact.added/updated` are store/update-side only).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handlePatientsContacts } from '../_shared/patients_contacts.ts';
import type { PatientContactRow, PatientsContactsDeps } from '../_shared/patients_contacts.ts';
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

const deps: PatientsContactsDeps = {
  ...identityDeps,

  listPatientContacts: (claims: Claims, id: string): PatientContactRow[] | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // The 404 gate: the patient itself must be visible under the claims
    // (TENANT_FACILITY). Out-of-scope ≡ nonexistent → null.
    const patient = db.queryObject<{ id: string }>(
      'select id from public.patients where id = $3 and tenant_id = $1 and facility_id = $2 limit 1',
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    if (patient === undefined) return null;

    // patient_contacts is TENANT_ONLY — the tenant claim + the verified
    // patient binding scope the read; ordered by is_primary DESC then
    // created_at ASC (the exact Laravel order). NO status filter. `address`
    // and `contact_person` are the decoded jsonb payloads; `value` is the
    // plain nullable text (no encryption at rest).
    const rows = db.queryObject<PatientContactDbRow>(
      `select id, type, value, address, contact_person, is_primary, status
         from public.patient_contacts
        where patient_id = $2 and tenant_id = $1
        order by is_primary desc, created_at asc`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, id],
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      type: row.type,
      value: row.value,
      address: row.address,
      contactPerson: row.contact_person,
      isPrimary: row.is_primary,
      status: row.status,
    }));
  },
};

Deno.serve((req) => handlePatientsContacts(req, deps));

/* ------------------------------------------------------------------ */

interface PatientContactDbRow {
  id: string;
  type: string;
  value: string | null;
  address: Record<string, unknown> | null;
  contact_person: Record<string, unknown> | null;
  is_primary: boolean;
  status: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'patients-contacts wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
