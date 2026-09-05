/**
 * lab-tests-update — PATCH /organizations/{organization}/lab-tests/{labTest} update/deactivate test (Phase 4 LIS).
 *
 * THIN DENO ADAPTER. Mirrors LabTestController::update():
 * - Updates test metadata or deactivates (status change)
 * - Validates tenant ownership
 */
import { handleLabTestsUpdate } from '../_shared/lab_tests_update.ts';
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

  updateLabTest: (claims: Claims, organizationId: string, testId: string, payload: { name?: string; category?: string; sampleType?: string; unit?: string; referenceRange?: string | null; method?: string | null; status?: string }) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // Organization gate
    const org = db.queryObject<{ id: string }>('select id from public.organizations where id = $1', [organizationId]).rows[0];
    if (!org) throw new Error('organization-not-found');
    if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) throw new Error('scope-denied');

    // Verify test belongs to organization
    const test = db.queryObject<{ id: string; tenant_id: string; lock_version: number }>(
      'select id, tenant_id, lock_version from public.lab_tests where id = $1', [testId],
    ).rows[0];
    if (!test) throw new Error('Lab test not found.');
    if (test.tenant_id !== organizationId) throw new Error('scope-denied');

    // Build update dynamically
    const updates: string[] = [];
    const params: unknown[] = [claims.app_user_id, testId, organizationId, test.lock_version];
    let paramIndex = 5;

    if (payload.name !== undefined) { updates.push(`name = $${paramIndex}`); params.push(payload.name); paramIndex++; }
    if (payload.category !== undefined) { updates.push(`category = $${paramIndex}`); params.push(payload.category); paramIndex++; }
    if (payload.sampleType !== undefined) { updates.push(`sample_type = $${paramIndex}`); params.push(payload.sampleType); paramIndex++; }
    if (payload.unit !== undefined) { updates.push(`unit = $${paramIndex}`); params.push(payload.unit); paramIndex++; }
    if (payload.referenceRange !== undefined) { updates.push(`reference_range = $${paramIndex}`); params.push(payload.referenceRange); paramIndex++; }
    if (payload.method !== undefined) { updates.push(`method = $${paramIndex}`); params.push(payload.method); paramIndex++; }
    if (payload.status !== undefined) { updates.push(`status = $${paramIndex}`); params.push(payload.status); paramIndex++; }

    if (updates.length === 0) throw new Error('No fields to update.');

    updates.push(`lock_version = lock_version + 1`);
    updates.push(`updated_by = $1`);
    updates.push(`updated_at = now()`);

    const updated = db.queryObject<{ id: string }>(
      `update public.lab_tests set ${updates.join(', ')} where id = $2 and tenant_id = $3 and lock_version = $4 returning id`,
      params,
    ).rows[0];
    if (!updated) throw new Error('Test was concurrently modified; refresh and retry.');

    db.execute(`insert into public.audit_events (tenant_id, event_type, auditable_type, auditable_id, payload, created_by) values ($1, 'lab.test.updated', 'lab_test', $2, $3, $4)`,
      [organizationId, testId, JSON.stringify(payload), claims.app_user_id]);

    return testId;
  },
};

Deno.serve((req) => handleLabTestsUpdate(req, deps));

function postgresFromEnv(): { execute(sql: string, params?: unknown[]): void; queryObject<T>(sql: string, params?: unknown[]): { rows: T[] }; } { throw new Error('lab-tests-update wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }