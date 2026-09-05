/**
 * lab-orders-specimens — GET /specimens facility-wide specimen worklist (Phase 4 LIS).
 *
 * THIN DENO ADAPTER. Mirrors LabOrderController::specimens():
 * - RLS-scoped list of specimens with status filtering, orderId filtering, pagination
 * - Tenant+facility isolation via RLS
 */
import { handleLabOrdersSpecimens } from '../_shared/lab_orders_specimens.ts';
import type { LabOrdersDeps } from '../_shared/lab_orders.ts';
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

  listSpecimens: (claims: Claims, params: { status?: string; labOrderId?: string; page: number; perPage: number }) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    const facilityId = claims.app_facility_id && claims.app_facility_id !== '' ? claims.app_facility_id : null;
    const tenantId = claims.app_tenant_id;

    const whereParts: string[] = ['s.tenant_id = $1'];
    const params: unknown[] = [tenantId];
    let paramIndex = 2;

    if (params.status) {
      whereParts.push(`s.status = $${paramIndex}`);
      params.push(params.status);
      paramIndex++;
    }
    if (params.labOrderId) {
      whereParts.push(`s.lab_order_id = $${paramIndex}`);
      params.push(params.labOrderId);
      paramIndex++;
    }
    if (facilityId) {
      whereParts.push(`s.facility_id = $${paramIndex}`);
      params.push(facilityId);
      paramIndex++;
    }

    const offset = (params.page - 1) * params.perPage;
    params.push(params.perPage, offset);

    const whereClause = whereParts.join(' and ');

    const countResult = db.queryObject<{ total: string }>(
      `select count(*)::text as total from public.specimens s ${whereClause}`,
      params.slice(0, paramIndex - 1),
    );
    const total = parseInt(countResult.rows[0]?.total ?? '0', 10);

    const rows = db.queryObject<SpecimenListRow>(
      `select s.id, s.lab_order_id, s.lab_order_item_id, s.specimen_type, s.status,
              s.collected_at, s.collected_by_staff_id, s.accessioned_at, s.accessioned_by_staff_id,
              s.processed_at, s.processed_by_staff_id, s.completed_at, s.rejected_at,
              s.rejected_by_staff_id, s.rejection_reason, s.lock_version, s.created_at
         from public.specimens s
        ${whereClause}
        order by s.created_at desc
        limit $${paramIndex} offset $${paramIndex + 1}`,
      params,
    ).rows;

    return { data: rows.map(presentSpecimen), total };
  },
};

Deno.serve((req) => handleLabOrdersSpecimens(req, deps));

interface SpecimenListRow {
  id: string;
  lab_order_id: string;
  lab_order_item_id: string | null;
  specimen_type: string;
  status: string;
  collected_at: string | null;
  collected_by_staff_id: string | null;
  accessioned_at: string | null;
  accessioned_by_staff_id: string | null;
  processed_at: string | null;
  processed_by_staff_id: string | null;
  completed_at: string | null;
  rejected_at: string | null;
  rejected_by_staff_id: string | null;
  rejection_reason: string | null;
  lock_version: number;
  created_at: string;
}

function presentSpecimen(row: SpecimenListRow) {
  return {
    id: row.id,
    labOrderId: row.lab_order_id,
    labOrderItemId: row.lab_order_item_id,
    specimenType: row.specimen_type,
    status: row.status,
    collectedAt: row.collected_at,
    collectedByStaffId: row.collected_by_staff_id,
    accessionedAt: row.accessioned_at,
    accessionedByStaffId: row.accessioned_by_staff_id,
    processedAt: row.processed_at,
    processedByStaffId: row.processed_by_staff_id,
    completedAt: row.completed_at,
    rejectedAt: row.rejected_at,
    rejectedByStaffId: row.rejected_by_staff_id,
    rejectionReason: row.rejection_reason,
    lockVersion: row.lock_version,
    createdAt: row.created_at,
  };
}

function postgresFromEnv(): { execute(sql: string, params?: unknown[]): void; queryObject<T>(sql: string, params?: unknown[]): { rows: T[] }; } { throw new Error('lab-orders-specimens wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }