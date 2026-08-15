/**
 * organizations-payers — the organization-scoped payer-catalog read Edge
 * Function (Phase 41).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/organizations_payers.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (PayerController
 * ::index + AccessCheck::organization parity):
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
 *   3. the payers SELECT is bound to the VERIFIED organization id and the
 *      claims — payers is **TENANT_ONLY** (the SIMPLEST tier: payers are
 *      TENANT-WIDE, NOT facility-scoped — a policy covers a patient at any
 *      facility of the tenant, so the select policy is `tenant_id = TENANT`
 *      with NO facility clause AND NO branch clause) — and ordered by
 *      `name` ASC — the exact `->orderBy('name')`. The Laravel query has NO
 *      facility filter (the `! isPlatform && facilityId() !== null` guard
 *      is ABSENT from PayerController::index) — a facility-scoped caller
 *      sees every payer of the tenant, reproduced exactly (there is NO
 *      facilityClause in this SELECT). NO status filter — active AND
 *      inactive both return (the catalog statuses). Payers are NOT
 *      soft-deletable — there is NO deleted_at filter (status is the
 *      lifecycle, no deleted_at column). Only the presented columns are
 *      selected — tenant/audit/timestamp metadata never leaves the
 *      function; `payer_type` mapped to `payerType`; no financial fields
 *      (payer rates live on the insurance policies, not the payer master);
 *   4. NO audit — PayerController::index records no audit event
 *      (`payer.created` / `payer.updated` are write-side events only).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleOrganizationsPayers } from '../_shared/organizations_payers.ts';
import type { OrganizationsPayersDeps, OrganizationPayersResult, PayerRow } from '../_shared/organizations_payers.ts';
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

const deps: OrganizationsPayersDeps = {
  ...identityDeps,

  listOrganizationPayers: (claims: Claims, organizationId: string): OrganizationPayersResult => {
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

    // payers is TENANT_ONLY — the claims scope the read by tenant alone.
    // There is NO facility filter and NO branch dimension: payers are
    // TENANT-WIDE (a policy covers a patient at any facility of the tenant)
    // and the Laravel query has no facility where — a facility-scoped
    // caller sees every payer of the tenant (reproduced exactly: this
    // SELECT has no facilityClause). Ordered by name ASC (the exact Laravel
    // order). NO status filter — active AND inactive both return. Payers
    // are NOT soft-deletable — no deleted_at filter (status is the
    // lifecycle). Only the presented columns are selected — tenant/audit/
    // timestamp metadata never leaves the function; `payer_type` mapped to
    // `payerType`; no financial fields (rates live on the policies).
    const rows = db.queryObject<PayerDbRow>(
      `select id, name, code, payer_type, status
         from public.payers
        where tenant_id = $1
        order by name asc`,
      [organizationId],
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      name: row.name,
      code: row.code,
      payerType: row.payer_type,
      status: row.status,
    }));
  },
};

Deno.serve((req) => handleOrganizationsPayers(req, deps));

/* ------------------------------------------------------------------ */

interface PayerDbRow {
  id: string;
  name: string;
  code: string;
  payer_type: string;
  status: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'organizations-payers wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
