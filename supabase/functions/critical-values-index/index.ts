/**
 * critical-values-index — GET /critical-values facility-wide critical value worklist (Phase 4 LIS).
 *
 * THIN DENO ADAPTER. Mirrors CriticalValueEventController::index():
 * - RLS-scoped list of critical value events with status filtering
 * - Tenant+facility isolation via RLS
 */
import { handleCriticalValuesIndex } from '../_shared/critical_values_index.ts';
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

  listCriticalValues: (claims: Claims, params: { status?: string; page: number; perPage: number }) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    const facilityId = claims.app_facility_id && claims.app_facility_id !== '' ? claims.app_facility_id : null;
    const tenantId = claims.app_tenant_id;

    const whereParts: string[] = ['cve.tenant_id = $1'];
    const params: unknown[] = [tenantId];
    let paramIndex = 2;

    if (params.status) {
      whereParts.push(`cve.status = $${paramIndex}`);
      params.push(params.status);
      paramIndex++;
    }
    if (facilityId) {
      whereParts.push(`cve.facility_id = $${paramIndex}`);
      params.push(facilityId);
      paramIndex++;
    }

    const offset = (params.page - 1) * params.perPage;
    params.push(params.perPage, offset);

    const whereClause = whereParts.join(' and ');

    const countResult = db.queryObject<{ total: string }>(
      `select count(*)::text as total from public.critical_value_events cve ${whereClause}`,
      params.slice(0, paramIndex - 1),
    );
    const total = parseInt(countResult.rows[0]?.total ?? '0', 10);

    const rows = db.queryObject<CriticalValueEventListRow>(
      `select cve.id, cve.lab_order_item_id, cve.result_value, cve.threshold_value,
              cve.direction, cve.status, cve.triggered_at, cve.acknowledged_at,
              cve.acknowledged_by_staff_id, cve.escalated_at, cve.escalated_by_staff_id,
              cve.lock_version, cve.created_at
         from public.critical_value_events cve
        ${whereClause}
        order by cve.triggered_at desc
        limit $${paramIndex} offset $${paramIndex + 1}`,
      params,
    ).rows;

    return { data: rows.map(presentCriticalValueEvent), total };
  },
};

Deno.serve((req) => handleCriticalValuesIndex(req, deps));

interface CriticalValueEventListRow {
  id: string;
  lab_order_item_id: string;
  result_value: string;
  threshold_value: string;
  direction: string;
  status: string;
  triggered_at: string;
  acknowledged_at: string | null;
  acknowledged_by_staff_id: string | null;
  escalated_at: string | null;
  escalated_by_staff_id: string | null;
  lock_version: number;
  created_at: string;
}

function presentCriticalValueEvent(row: CriticalValueEventListRow) {
  return {
    id: row.id,
    labOrderItemId: row.lab_order_item_id,
    resultValue: row.result_value,
    thresholdValue: row.threshold_value,
    direction: row.direction,
    status: row.status,
    triggeredAt: row.triggered_at,
    acknowledgedAt: row.acknowledged_at,
    acknowledgedByStaffId: row.acknowledged_by_staff_id,
    escalatedAt: row.escalated_at,
    escalatedByStaffId: row.escalated_by_staff_id,
    lockVersion: row.lock_version,
    createdAt: row.created_at,
  };
}

function postgresFromEnv(): { execute(sql: string, params?: unknown[]): void; queryObject<T>(sql: string, params?: unknown[]): { rows: T[] }; } { throw new Error('critical-values-index wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }