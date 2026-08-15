/**
 * patients-identifiers — the patient-scoped identity-document read Edge
 * Function (Phase 28).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/patients_identifiers.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (PatientIdentifier
 * Controller::index + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the patient gate SELECT runs as swasthya_app — the claims-scoped
 *      patients policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth — the id is a resource selector, never
 *      authorization scope;
 *   3. the patient_identifiers SELECT is bound to the VERIFIED patient id
 *      and the tenant claim (patient_identifiers is a TENANT_ONLY
 *      claims-scoped table — the patient gate already scoped the caller to
 *      this facility) and ordered by created_at DESC — the exact
 *      `->orderByDesc('created_at')`. NO status filter — active and
 *      superseded identifiers both return (the Laravel query has none).
 *      `is_verified` is a boolean; `issuing_country` is nullable;
 *   4. the `value` presentation boundary: in Laravel, `value` is the
 *      EncryptedString-cast PLAINTEXT (AES-256-GCM under the application
 *      key — ciphertext at rest in `value_encrypted`, SECURITY.md §12).
 *      The edge cannot hold the Laravel app key, so the adapter passes the
 *      STORED ciphertext through the dependency row and live decryption is
 *      classified REQUIRES REAL SUPABASE (an equivalent app-layer cast or
 *      key-shared decrypt at the platform boundary). The harness proves the
 *      full response contract with the cast simulated; the PHP DB tier
 *      proves ciphertext-at-rest + the deterministic sha256 `value_hash` +
 *      scope/ordering/status semantics on real PostgreSQL. No plaintext is
 *      ever written to logs, audit payloads, or fixtures;
 *   5. NO audit — PatientIdentifierController::index records no audit
 *      event (`patient.identifier.added` is store-side only).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handlePatientsIdentifiers } from '../_shared/patients_identifiers.ts';
import type { PatientIdentifierRow, PatientsIdentifiersDeps } from '../_shared/patients_identifiers.ts';
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

const deps: PatientsIdentifiersDeps = {
  ...identityDeps,

  listPatientIdentifiers: (claims: Claims, id: string): PatientIdentifierRow[] | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // The 404 gate: the patient itself must be visible under the claims
    // (TENANT_FACILITY). Out-of-scope ≡ nonexistent → null.
    const patient = db.queryObject<{ id: string }>(
      'select id from public.patients where id = $3 and tenant_id = $1 and facility_id = $2 limit 1',
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    if (patient === undefined) return null;

    // patient_identifiers is TENANT_ONLY — the tenant claim + the verified
    // patient binding scope the read; ordered by created_at DESC (the exact
    // Laravel order). NO status filter. `value` passes the stored ciphertext
    // through — live decryption is the EncryptedString app-layer boundary
    // (REQUIRES REAL SUPABASE, see header).
    const rows = db.queryObject<PatientIdentifierDbRow>(
      `select id, type, value_encrypted, issuing_country, is_verified, status
         from public.patient_identifiers
        where patient_id = $2 and tenant_id = $1
        order by created_at desc`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, id],
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      type: row.type,
      value: row.value_encrypted,
      issuingCountry: row.issuing_country,
      isVerified: row.is_verified,
      status: row.status,
    }));
  },
};

Deno.serve((req) => handlePatientsIdentifiers(req, deps));

/* ------------------------------------------------------------------ */

interface PatientIdentifierDbRow {
  id: string;
  type: string;
  value_encrypted: string;
  issuing_country: string | null;
  is_verified: boolean;
  status: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'patients-identifiers wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
