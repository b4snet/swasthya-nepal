/**
 * lab-tests-index — GET /organizations/{organization}/lab-tests catalog (Phase 4 LIS).
 *
 * THIN DENO ADAPTER. Mirrors LabTestController::index():
 * - RLS-scoped list of lab tests (tenant+facility)
 * - Optional status filter (active/inactive/both)
 */
import { handleLabTestsIndex } from '../_shared/lab_tests_index.ts';
import type { Claims } from '../_shared/lab_types.ts';
import type { HealthAuthDeps } from '../_shared/pipeline.ts';

const db = postgresFromEnv();

const identityDeps: HealthAuthDeps = {
  secret: Deno.env.get('SUPABASE_JWT_SECRET') ?? '',
  issuer: 'supabase',
  audience: 'authenticated',
  findUserBySubject: (sub) => { const row = db.queryObject<{ id: string; email: string | null; status: string }>('select id, email, status from public.users where auth_subject_id = $1 limit 1', [sub]).rows[0]; return row ? { id: row.id, email: row.email ?? undefined, status: row.status as AppUserStatus } : null; },
  loadActiveAssignments: (userId) => db.queryObject<AssignmentRow>(`select ra.id, ra.user_id, ra.role_id, ra.tenant_id, ra.facility_id, ra.branch_id, ra.scope_type, r.code as role_code, r.scope_type as role_scope_type from public.role_assignments ra join public.roles r on r.id = ra.role_id where ra.user_id = $1 and ra.status = 'active'`, [userId]).rows.map((row) => ({ id: row.id, userId: row.user_id, roleId: row.role_id, tenantId: row.tenant_id, facilityId: row.facility_id, branchId: row.branch_id, scopeType: row.scope_type, role: { id: row.role_id, code: row.role_code, scopeType: row.role_scope_type, permissions: [] } })),
  activeSupportSession: (userId) => { const row = db.queryObject<{ id: string; organization_id: string; facility_id: string | null }>('select id, organization_id, facility_id from public.support_sessions where user_id = $1 and status = \'active\' and expires_at > now() order by opened_at desc limit 1', [userId]).rows[0]; return row ? { id: row.id, organizationId: row.organization_id, facilityId: row.facility_id } : null; },
  loadOrganization: (id) => { const row = db.queryObject<{ id: string; status: string; timezone: string | null }>('select id, status, timezone from public.organizations where id = $1', [id]).rows[0]; return row ? { id: row.id, status: row.status, timezone: row.timezone ?? undefined } : null; },
  loadFacility: (id) => { const row = db.queryObject<{ id: string; tenant_id: string; timezone: string | null }>('select id, tenant_id, timezone from public.facilities where id = $1', [id]).rows[0]; return row ? { id: row.id, tenantId: row.tenant_id, timezone: row.timezone ?? undefined } : null; },
  loadBranch: (id) => { const row = db.queryObject<{ id: string; tenant_id: string; facility_id: string }>('select id, tenant_id, facility_id from public.branches where id = $1', [id]).rows[0]; return row ? { id: row.id, tenantId: row.tenant_id, facilityId: row.facility_id } : null; },
};

const deps = {
  ...identityDeps,

  listLabTests: (claims: Claims, organizationId: string, params: { status?: string; page: number; perPage: number }) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // Organization gate
    const org = db.queryObject<{ id: string }>('select id from public.organizations where id = $1', [organizationId]).rows[0];
    if (!org) throw new Error('organization-not-found');
    if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) throw new Error('scope-denied');

    const facilityId = claims.app_facility_id && claims.app_facility_id !== '' ? claims.app_facility_id : null;

    const whereParts: string[] = ['lt.tenant_id = $1 and lt.deleted_at is null'];
    const params: unknown[] = [organizationId];
    let paramIndex = 2;

    if (params.status) {
      whereParts.push(`lt.status = $${paramIndex}`);
      params.push(params.status);
      paramIndex++;
    }
    if (facilityId) {
      whereParts.push(`lt.facility_id = $${paramIndex}`);
      params.push(facilityId);
      paramIndex++;
    }

    const offset = (params.page - 1) * params.perPage;
    params.push(params.perPage, offset);

    const whereClause = whereParts.join(' and ');

    const rows = db.queryObject<LabTestListRow>(
      `select lt.id, lt.facility_id, lt.code, lt.generic_name, lt.name, lt.category,
              lt.sample_type, lt.unit, lt.reference_range, lt.method, lt.status,
              lt.lock_version, lt.created_at
         from public.lab_tests lt
        ${whereClause}
        order by lt.code asc
        limit $${paramIndex} offset $${paramIndex + 1}`,
      params,
    ).rows;

    return rows.map(presentLabTest);
  },
};

Deno.serve((req) => handleLabTestsIndex(req, deps));

interface LabTestListRow {
  id: string;
  facility_id: string | null;
  code: string;
  generic_name: string;
  name: string;
  category: string;
  sample_type: string;
  unit: string;
  reference_range: string | null;
  method: string | null;
  status: string;
  lock_version: number;
  created_at: string;
}

function presentLabTest(row: LabTestListRow) {
  return {
    id: row.id,
    facilityId: row.facility_id,
    code: row.code,
    genericName: row.generic_name,
    name: row.name,
    category: row.category,
    sampleType: row.sample_type,
    unit: row.unit,
    referenceRange: row.reference_range,
    method: row.method,
    status: row.status,
    lockVersion: row.lock_version,
    createdAt: row.created_at,
  };
}

function postgresFromEnv(): { execute(sql: string, params?: unknown[]): void; queryObject<T>(sql: string, params?: unknown[]): { rows: T[] }; } { throw new Error('lab-tests-index wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }