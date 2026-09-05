/**
 * lab-orders-collect — POST /lab-orders/{id}/collect specimen collection (Phase 4 LIS).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/lab_orders_collect.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime.
 *
 * Mirrors LabOrderController::collectSpecimens():
 * - status: ordered → collected (CAS on lock_version)
 * - records collected_at, collected_by_staff_id
 * - creates Specimen rows for each item (status=collected)
 * - audit: lab.specimen.collected
 */
import { handleLabOrdersCollect } from '../_shared/lab_orders_collect.ts';
import type { LabOrdersDeps } from '../_shared/lab_orders.ts';
import type { Claims } from '../_shared/lab_types.ts';
import type { HealthAuthDeps } from '../_shared/pipeline.ts';

const db = postgresFromEnv();

const identityDeps: HealthAuthDeps = {
  secret: Deno.env.get('SUPABASE_JWT_SECRET') ?? '',
  issuer: 'supabase',
  audience: 'authenticated',
  findUserBySubject: (sub) => {
    const row = db.queryObject<{ id: string; email: string | null; status: string }>(
      'select id, email, status from public.users where auth_subject_id = $1 limit 1', [sub],
    ).rows[0];
    return row ? { id: row.id, email: row.email ?? undefined, status: row.status as AppUserStatus } : null;
  },
  loadActiveAssignments: (userId) =>
    db.queryObject<AssignmentRow>(
      `select ra.id, ra.user_id, ra.role_id, ra.tenant_id, ra.facility_id, ra.branch_id, ra.scope_type,
              r.code as role_code, r.scope_type as role_scope_type
         from public.role_assignments ra join public.roles r on r.id = ra.role_id
        where ra.user_id = $1 and ra.status = 'active'`, [userId],
    ).rows.map((row) => ({ id: row.id, userId: row.user_id, roleId: row.role_id, tenantId: row.tenant_id, facilityId: row.facility_id, branchId: row.branch_id, scopeType: row.scope_type, role: { id: row.role_id, code: row.role_code, scopeType: row.role_scope_type, permissions: [] } })),
  activeSupportSession: (userId) => {
    const row = db.queryObject<{ id: string; organization_id: string; facility_id: string | null }>(
      `select id, organization_id, facility_id from public.support_sessions where user_id = $1 and status = 'active' and expires_at > now() order by opened_at desc limit 1`, [userId],
    ).rows[0];
    return row ? { id: row.id, organizationId: row.organization_id, facilityId: row.facility_id } : null;
  },
  loadOrganization: (id) => { const row = db.queryObject<{ id: string; status: string; timezone: string | null }>('select id, status, timezone from public.organizations where id = $1', [id]).rows[0]; return row ? { id: row.id, status: row.status, timezone: row.timezone ?? undefined } : null; },
  loadFacility: (id) => { const row = db.queryObject<{ id: string; tenant_id: string; timezone: string | null }>('select id, tenant_id, timezone from public.facilities where id = $1', [id]).rows[0]; return row ? { id: row.id, tenantId: row.tenant_id, timezone: row.timezone ?? undefined } : null; },
  loadBranch: (id) => { const row = db.queryObject<{ id: string; tenant_id: string; facility_id: string }>('select id, tenant_id, facility_id from public.branches where id = $1', [id]).rows[0]; return row ? { id: row.id, tenantId: row.tenant_id, facilityId: row.facility_id } : null; },
};

const deps = {
  ...identityDeps,

  collectSpecimens: (claims: Claims, orderId: string, collectedByStaffId: string) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // Verify order exists and is in correct status
    const order = db.queryObject<{ id: string; status: string; lock_version: number; facility_id: string }>(
      'select id, status, lock_version, facility_id from public.lab_orders where id = $1 and tenant_id = $2',
      [claims.app_tenant_id, orderId], // orderId is in path, tenant from claims
    ).rows[0];
    if (!order) throw new Error('Lab order not found.');
    if (order.status !== 'ordered') throw new Error('Only ordered lab orders can be collected.');

    // CAS transition ordered → collected
    const updated = db.queryObject<{ id: string }>(
      `update public.lab_orders set status = 'collected', collected_at = now(), collected_by_staff_id = $1,
              lock_version = lock_version + 1, updated_by = $1, updated_at = now()
       where id = $2 and tenant_id = $3 and status = 'ordered' and lock_version = $4
       returning id`,
      [claims.app_user_id, orderId, claims.app_tenant_id, order.lock_version],
    ).rows[0];
    if (!updated) throw new Error('Order was concurrently modified; refresh and retry.');

    // Create specimen rows for each order item
    const items = db.queryObject<{ id: string; lab_test_id: string }>(
      'select id, lab_test_id from public.lab_order_items where lab_order_id = $1', [orderId],
    ).rows;
    for (const item of items) {
      db.execute(
        `insert into public.specimens (lab_order_id, lab_order_item_id, specimen_type, status, collected_at, collected_by_staff_id, lock_version, created_by)
         values ($1, $2, (select sample_type from public.lab_tests where id = (select lab_test_id from public.lab_order_items where id = $3)), 'collected', now(), $4, 0, $5)`,
        [orderId, item.id, item.id, claims.app_user_id, claims.app_user_id],
      );
    }

    // Audit
    db.execute(`insert into public.audit_events (tenant_id, event_type, auditable_type, auditable_id, payload, created_by) values ($1, 'lab.specimen.collected', 'lab_order', $2, $3, $4)`,
      [claims.app_tenant_id, orderId, JSON.stringify({ collectedByStaffId: claims.app_user_id }), claims.app_user_id]);

    return { orderId };
  },
};

Deno.serve((req) => handleLabOrdersCollect(req, deps));

function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} { throw new Error('lab-orders-collect wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }