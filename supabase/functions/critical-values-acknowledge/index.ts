/**
 * critical-values-acknowledge — POST /critical-values/{id}/acknowledge (Phase 4 LIS).
 *
 * THIN DENO ADAPTER. Mirrors CriticalValueEventController::acknowledge():
 * - status: triggered → acknowledged (CAS on lock_version)
 * - records acknowledged_at, acknowledged_by_staff_id
 * - One open event per item enforced by DB constraint
 * - audit: lab.critical.acknowledged
 */
import { handleCriticalValuesAcknowledge } from '../_shared/critical_values_acknowledge.ts';
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

  acknowledgeCriticalValue: (claims: Claims, eventId: string, acknowledgedByStaffId: string) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    const event = db.queryObject<{ id: string; status: string; lock_version: number; lab_order_item_id: string }>(
      'select id, status, lock_version, lab_order_item_id from public.critical_value_events where id = $1 and tenant_id = $2', [eventId, claims.app_tenant_id],
    ).rows[0];
    if (!event) throw new Error('Critical value event not found.');
    if (event.status !== 'triggered') throw new Error('Only triggered events can be acknowledged.');

    // CAS transition triggered → acknowledged
    const updated = db.queryObject<{ id: string }>(
      `update public.critical_value_events set status = 'acknowledged', acknowledged_at = now(), acknowledged_by_staff_id = $1,
              lock_version = lock_version + 1, updated_by = $1, updated_at = now()
       where id = $2 and tenant_id = $3 and status = 'triggered' and lock_version = $4
       returning id`,
      [claims.app_user_id, eventId, claims.app_tenant_id, event.lock_version],
    ).rows[0];
    if (!updated) throw new Error('Event was concurrently modified; refresh and retry.');

    db.execute(`insert into public.audit_events (tenant_id, event_type, auditable_type, auditable_id, payload, created_by) values ($1, 'lab.critical.acknowledged', 'critical_value_event', $2, $3, $4)`,
      [claims.app_tenant_id, eventId, JSON.stringify({ acknowledgedByStaffId: claims.app_user_id, labOrderItemId: event.lab_order_item_id }), claims.app_user_id]);

    return { eventId };
  },
};

Deno.serve((req) => handleCriticalValuesAcknowledge(req, deps));

function postgresFromEnv(): { execute(sql: string, params?: unknown[]): void; queryObject<T>(sql: string, params?: unknown[]): { rows: T[] }; } { throw new Error('critical-values-acknowledge wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.'); }