/**
 * lab-orders-verify — POST /lab-orders/{id}/verify (Phase 4 LIS).
 *
 * THIN DENO ADAPTER. Mirrors LabOrderController::verify():
 * - status: results_entered → verified (CAS on lock_version)
 * - records verified_at, verified_by_staff_id
 * - updates LabOrderItems: status=verified, verified_by_staff_id, verified_at
 * - ENTRY ≠ VERIFICATION enforced: verified_by_staff_id must differ from entered_by_staff_id
 * - audit: lab.result.verified
 */
import { handleLabOrdersVerify } from '../_shared/lab_orders_verify.ts';
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

  verifyOrder: (claims: Claims, orderId: string, verifiedByStaffId: string, itemIds: string[]) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    const order = db.queryObject<{ id: string; status: string; lock_version: number }>(
      'select id, status, lock_version from public.lab_orders where id = $1 and tenant_id = $2', [orderId, claims.app_tenant_id],
    ).rows[0];
    if (!order) throw new Error('Lab order not found.');
    if (order.status !== 'results_entered') throw new Error('Only results_entered lab orders can be verified.');

    // Verify each item: must be results_entered, verifier ≠ enterer
    for (const itemId of itemIds) {
      const item = db.queryObject<{ id: string; entered_by_staff_id: string; status: string }>(
        'select id, entered_by_staff_id, status from public.lab_order_items where id = $1 and lab_order_id = $2', [itemId, orderId],
      ).rows[0];
      if (!item) throw new Error(`Lab order item ${itemId} not found on this order.`);
      if (item.status !== 'results_entered') throw new Error(`Item ${itemId} is not in results_entered state.`);
      if (item.entered_by_staff_id === verifiedByStaffId) {
        throw new Error('The verifying staff must be different from the staff who entered the results.');
      }
    }

    // CAS transition results_entered → verified
    const updated = db.queryObject<{ id: string }>(
      `update public.lab_orders set status = 'verified', verified_at = now(), verified_by_staff_id = $1,
              lock_version = lock_version + 1, updated_by = $1, updated_at = now()
       where id = $2 and tenant_id = $3 and status = 'results_entered' and lock_version = $4
       returning id`,
      [claims.app_user_id, orderId, claims.app_tenant_id, order.lock_version],
    ).rows[0];
    if (!updated) throw new Error('Order was concurrently modified; refresh and retry.');

    // Update items
    for (const itemId of itemIds) {
      db.execute(
        `update public.lab_order_items set status = 'verified', verified_by_staff_id = $1, verified_at = now(),
                lock_version = lock_version + 1, updated_by = $1, updated_at = now()
         where id = $2 and lab_order_id = $3`,
        [claims.app_user_id, itemId, orderId],
      );
    }

    db.execute(`insert into public.audit_events (tenant_id, event_type, auditable_type, auditable_id, payload, created_by) values ($1, 'lab.result.verified', 'lab_order', $2, $3, $4)`,
      [claims.app_tenant_id, orderId, JSON.stringify({ verifiedByStaffId: claims.app_user_id, itemCount: itemIds.length }), claims.app_user_id]);

    return { orderId };
  },
};

Deno.serve((req) => handleLabOrdersVerify(req, deps));

function postgresFromEnv(): { execute(sql: string, params?: unknown[]): void; queryObject<T>(sql: string, params?: unknown[]): { rows: T[] }; } { throw new Error('lab-orders-verify wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }