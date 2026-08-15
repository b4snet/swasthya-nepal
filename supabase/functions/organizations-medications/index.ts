/**
 * organizations-medications — the organization-scoped formulary read Edge
 * Function (Phase 42).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/organizations_medications.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (MedicationController
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
 *   3. the medications SELECT is bound to the VERIFIED organization id and
 *      the claims — medications is **TENANT_FACILITY** (NOT
 *      TENANT_FACILITY_BRANCH: medications has NO branch_id column, so the
 *      select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
 *      FACILITY IS NULL)` — there is NO branch clause; a branch proposal
 *      does NOT narrow this read) — and ordered by `generic_name` ASC —
 *      the exact `->orderBy('generic_name')`. **Medications ARE
 *      soft-deletable** — the Laravel SoftDeletes model scope excludes
 *      soft-deleted rows, so the SELECT has `m.deleted_at is null` exactly
 *      as the model's default scope does. The facility filter is applied
 *      ONLY when the caller has a facility claim (the exact `! isPlatform
 *      && facilityId() !== null` guard — org-level / platform callers see
 *      every facility of the tenant). NO status filter — active AND
 *      inactive both return (the catalog statuses). The controller performs
 *      NO partial select, so `facility_id` (NOT NULL in the base schema) is
 *      selected and HYDRATED — `facilityId` is a contract-explicit
 *      presented field carrying the real value; `brand_name` is NULLABLE
 *      (the only nullable text field); `strength`/`form`/`unit` are NOT
 *      NULL (form defaults to 'tablet'); `price_minor` is an integer in
 *      minor units (never floats — DATABASE.md §0.4, `price_minor >= 0`
 *      CHECK); `currency` is the 3-char ISO code (default 'NPR');
 *      `is_controlled` is a boolean; `lock_version` is NEVER selected —
 *      the read projection is exactly the presented map;
 *   4. NO audit — MedicationController::index records no audit event
 *      (`medication.created` is the only audit path, write-side).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleOrganizationsMedications } from '../_shared/organizations_medications.ts';
import type { OrganizationsMedicationsDeps, OrganizationMedicationsResult, MedicationRow } from '../_shared/organizations_medications.ts';
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

const deps: OrganizationsMedicationsDeps = {
  ...identityDeps,

  listOrganizationMedications: (claims: Claims, organizationId: string): OrganizationMedicationsResult => {
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

    // medications is TENANT_FACILITY — the claims scope the read; the
    // facility filter applies ONLY when the caller has a facility claim
    // (the exact Laravel guard); ordered by generic_name ASC (the exact
    // Laravel order). **Medications ARE soft-deletable** — the Laravel
    // SoftDeletes model scope excludes soft-deleted rows, so
    // `m.deleted_at is null` is the exact model default-scope parity. NO
    // status filter — active AND inactive both return (the catalog
    // statuses). Only the presented columns are selected — `lock_version`,
    // tenant/audit/timestamp metadata never leave the function.
    // `facility_id` is NOT NULL (base schema) and selected + hydrated
    // (`facilityId` carries the real value); `brand_name` is NULLABLE (the
    // only nullable text field); `strength`/`form`/`unit` NOT NULL;
    // `price_minor` integer minor units (>= 0 CHECK); `currency` 3-char.
    const facilityClause = claims.app_facility_id === ''
      ? ''
      : ' and m.facility_id = $2';
    const params: unknown[] = [organizationId];
    if (facilityClause !== '') params.push(claims.app_facility_id);

    const rows = db.queryObject<MedicationDbRow>(
      `select m.id, m.facility_id, m.code, m.generic_name, m.brand_name,
              m.strength, m.form, m.unit, m.price_minor, m.currency,
              m.is_controlled, m.status
         from public.medications m
        where m.tenant_id = $1 and m.deleted_at is null${facilityClause}
        order by m.generic_name asc`,
      params,
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      facilityId: row.facility_id,
      code: row.code,
      genericName: row.generic_name,
      brandName: row.brand_name,
      strength: row.strength,
      form: row.form,
      unit: row.unit,
      priceMinor: row.price_minor,
      currency: row.currency,
      isControlled: row.is_controlled,
      status: row.status,
    } satisfies MedicationRow));
  },
};

Deno.serve((req) => handleOrganizationsMedications(req, deps));

/* ------------------------------------------------------------------ */

interface MedicationDbRow {
  id: string;
  facility_id: string;
  code: string;
  generic_name: string;
  brand_name: string | null;
  strength: string;
  form: string;
  unit: string;
  price_minor: number;
  currency: string;
  is_controlled: boolean;
  status: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'organizations-medications wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
