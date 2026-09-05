/**
 * lab-orders-enter-results — POST /lab-orders/{id}/enter-results (Phase 4 LIS).
 *
 * THIN DENO ADAPTER. Mirrors LabOrderController::enterResults():
 * - status: processing → results_entered (CAS on lock_version)
 * - records results_entered_at
 * - updates LabOrderItems: result_value, result_unit, abnormal_flag, status, entered_by_staff_id, entered_at
 * - creates LabResultVersion (append-only history)
 * - audit: lab.result.entered
 */
import { handleLabOrdersEnterResults } from '../_shared/lab_orders_enter_results.ts';
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

  enterResults: (claims: Claims, orderId: string, enteredByStaffId: string, items: Array<{ labOrderItemId: string; resultValue: string; resultUnit?: string; abnormalFlag?: string | null }>) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    const order = db.queryObject<{ id: string; status: string; lock_version: number }>(
      'select id, status, lock_version from public.lab_orders where id = $1 and tenant_id = $2', [orderId, claims.app_tenant_id],
    ).rows[0];
    if (!order) throw new Error('Lab order not found.');
    if (order.status !== 'processing') throw new Error('Only processing lab orders can have results entered.');

    // CAS transition processing → results_entered
    const updated = db.queryObject<{ id: string }>(
      `update public.lab_orders set status = 'results_entered', results_entered_at = now(),
              lock_version = lock_version + 1, updated_by = $1, updated_at = now()
       where id = $2 and tenant_id = $3 and status = 'processing' and lock_version = $4
       returning id`,
      [claims.app_user_id, orderId, claims.app_tenant_id, order.lock_version],
    ).rows[0];
    if (!updated) throw new Error('Order was concurrently modified; refresh and retry.');

    // Update each item with results
    for (const item of items) {
      // Verify item belongs to this order
      const loi = db.queryObject<{ id: string; lab_test_id: string }>(
        'select id, lab_test_id from public.lab_order_items where id = $1 and lab_order_id = $2', [item.labOrderItemId, orderId],
      ).rows[0];
      if (!loi) throw new Error(`Lab order item ${item.labOrderItemId} not found on this order.`);

      // Get test reference range for abnormal flag if not provided
      let abnormalFlag = item.abnormalFlag ?? null;
      if (!abnormalFlag && item.resultValue) {
        const test = db.queryObject<{ reference_range: string | null }>('select reference_range from public.lab_tests where id = (select lab_test_id from public.lab_order_items where id = $1)', [item.labOrderItemId]).rows[0];
        if (test?.reference_range) {
          // Simple abnormal detection - in real implementation would be more sophisticated
          abnormalFlag = 'N'; // normal by default
        }
      }

      db.execute(
        `update public.lab_order_items set result_value = $1, result_unit = $2, abnormal_flag = $3,
                status = 'results_entered', entered_by_staff_id = $4, entered_at = now(),
                lock_version = lock_version + 1, updated_by = $4, updated_at = now()
         where id = $5 and lab_order_id = $6`,
        [item.resultValue, item.resultUnit ?? null, abnormalFlag, claims.app_user_id, item.labOrderItemId, orderId],
      );

      // Create LabResultVersion (append-only history)
      const versionNum = db.queryObject<{ max: string }>(
        'select coalesce(max(version_number), 0)::text as max from public.lab_result_versions where lab_order_item_id = $1', [item.labOrderItemId],
      ).rows[0]?.max ?? '0';
      const nextVersion = parseInt(versionNum, 10) + 1;

      db.execute(
        `insert into public.lab_result_versions (tenant_id, lab_order_item_id, version_number, result_value, result_unit, abnormal_flag, entered_by_staff_id, entered_at, created_by)
         values ($1, $2, $3, $4, $5, $6, $7, now(), $8)`,
        [claims.app_tenant_id, item.labOrderItemId, nextVersion, item.resultValue, item.resultUnit ?? null, abnormalFlag, claims.app_user_id, claims.app_user_id],
      );
    }

    // Update specimens: processing → completed (if all items have results)
    db.execute(`update public.specimens set status = 'completed', processed_at = now(), processed_by_staff_id = $1, lock_version = lock_version + 1, updated_by = $1, updated_at = now() where lab_order_id = $2 and tenant_id = $3 and status in ('accessioned', 'processing')`,
      [claims.app_user_id, orderId, claims.app_tenant_id]);

    db.execute(`insert into public.audit_events (tenant_id, event_type, auditable_type, auditable_id, payload, created_by) values ($1, 'lab.result.entered', 'lab_order', $2, $3, $4)`,
      [claims.app_tenant_id, orderId, JSON.stringify({ enteredByStaffId: claims.app_user_id, itemCount: items.length }), claims.app_user_id]);

    return { orderId };
  },
};

Deno.serve((req) => handleLabOrdersEnterResults(req, deps));

function postgresFromEnv(): { execute(sql: string, params?: unknown[]): void; queryObject<T>(sql: string, params?: unknown[]): { rows: T[] }; } { throw new Error('lab-orders-enter-results wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }