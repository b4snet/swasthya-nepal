/**
 * lab-tests-store — POST /organizations/{organization}/lab-tests create test (Phase 4 LIS).
 *
 * THIN DENO ADAPTER. Mirrors LabTestController::store():
 * - Creates lab test in tenant's catalog
 * - Validates code uniqueness per tenant
 */
import { handleLabTestsStore } from '../_shared/lab_tests_store.ts';
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

  createLabTest: (claims: Claims, organizationId: string, payload: { code: string; name: string; category: string; sampleType: string; unit: string; referenceRange?: string | null; method?: string | null; status?: string }) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // Organization gate
    const org = db.queryObject<{ id: string }>('select id from public.organizations where id = $1', [organizationId]).rows[0];
    if (!org) throw new Error('organization-not-found');
    if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) throw new Error('scope-denied');

    // Check code uniqueness per tenant
    const existing = db.queryObject<{ id: string }>('select id from public.lab_tests where tenant_id = $1 and code = $2', [organizationId, payload.code]).rows[0];
    if (existing) throw new Error('A lab test with this code already exists in this organization.');

    const result = db.queryObject<{ id: string }>(
      `insert into public.lab_tests (tenant_id, facility_id, code, generic_name, name, category, sample_type, unit, reference_range, method, status, lock_version, created_by)
       values ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, 0, $12)
       returning id`,
      [organizationId, claims.app_facility_id ?? null, payload.code, payload.name, payload.name, payload.category, payload.sampleType, payload.unit, payload.referenceRange ?? null, payload.method ?? null, payload.status ?? 'active', claims.app_user_id],
    ).rows[0];

    db.execute(`insert into public.audit_events (tenant_id, event_type, auditable_type, auditable_id, payload, created_by) values ($1, 'lab.test.created', 'lab_test', $2, $3, $4)`,
      [organizationId, result!.id, JSON.stringify({ code: payload.code, name: payload.name }), claims.app_user_id]);

    return result!.id;
  },
};

Deno.serve((req) => handleLabTestsStore(req, deps));

function postgresFromEnv(): { execute(sql: string, params?: unknown[]): void; queryObject<T>(sql: string, params?: unknown[]): { rows: T[] }; } { throw new Error('lab-tests-store wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }