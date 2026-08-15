/**
 * encounters-charges — the posted charges of one encounter Edge Function
 * (Phase 19).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/encounters_charges.ts
 * (pure, dependency-free, proven by the local harness). This file only
 * wires the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md, "validation
 * tiers").
 *
 * The RLS-scoped read is the production-critical wiring (EncounterController
 * ::charges + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the encounter SELECT by id runs as swasthya_app — the claims-scoped
 *      encounters policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth only — the id is a resource selector, never
 *      authorization scope;
 *   3. the charges SELECT runs under the same claims (charges is
 *      TENANT_FACILITY) bound to the verified encounter id and ordered by
 *      charged_at ascending (`->orderBy('charged_at')`) — ALL statuses are
 *      returned, including voided (the Laravel hasMany has no status
 *      filter); the presented status lets the client see them;
 *   4. the presented shape mirrors EncounterController::charges exactly:
 *      {id, sourceType, description, amountMinor, currency, status,
 *      chargedAt} — no invoice/patient/related data (the contract includes
 *      none);
 *   5. NO audit — EncounterController::charges records no audit event.
 *
 * No mutations. No client-supplied tenant/facility/branch value ever
 * becomes authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleEncountersCharges } from '../_shared/encounters_charges.ts';
import type { EncountersChargesDeps, ChargeRow } from '../_shared/encounters_charges.ts';
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

const deps: EncountersChargesDeps = {
  ...identityDeps,
  listEncounterCharges: (claims: Claims, id: string): ChargeRow[] | null => {
    // The claims are server-derived. Set the GUC, then let RLS decide
    // visibility; the explicit scope WHERE on the encounter lookup is
    // defense-in-depth.
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const encounter = db.queryObject<{ id: string }>(
      `select id
         from public.encounters
        where id = $3
          and tenant_id = $1 and facility_id = $2
        limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    if (encounter === undefined) return null;

    // Charges under the same claims (charges is TENANT_FACILITY), bound to
    // the verified encounter id and ordered by charged_at exactly as
    // `->orderBy('charged_at')`. All statuses (including voided) return.
    const rows = db.queryObject<ChargeDbRow>(
      `select id, source_type, description, amount_minor, currency, status, charged_at
         from public.charges
        where encounter_id = $1
        order by charged_at asc`,
      [id],
    ).rows;

    // Present ONLY the approved charge fields — no invoice/patient/related
    // data (the Laravel contract includes none).
    return rows.map((row) => ({
      id: row.id,
      sourceType: row.source_type,
      description: row.description,
      amountMinor: row.amount_minor,
      currency: row.currency,
      status: row.status,
      chargedAt: row.charged_at,
    }));
  },
};

Deno.serve((req) => handleEncountersCharges(req, deps));

/* ------------------------------------------------------------------ */

interface ChargeDbRow {
  id: string;
  source_type: string;
  description: string;
  amount_minor: number;
  currency: string;
  status: string;
  charged_at: string | null;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'encounters-charges wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
