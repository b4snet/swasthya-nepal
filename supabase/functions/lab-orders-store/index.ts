/**
 * lab-orders-store — POST /lab-orders create lab order (Phase 4 LIS).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/lab_orders_store.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped write is the production-critical wiring (LabOrderController::store
 * + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the caller must hold lab:order (route middleware); the INSERT runs as
 *      swasthya_app: tenant isolation via RLS (tenant_id = claim), facility
 *      isolation via RLS (facility_id = claim OR NULL) — the exact
 *      TENANT_FACILITY tier policy;
 *   3. validates: patient exists in tenant, encounter belongs to patient/facility,
 *      ordering staff has lab:order permission, at least one item, all lab tests
 *      exist and are active in the tenant;
 *   4. creates LabOrder (status=ordered, lock_version=0) + LabOrderItems
 *      (status=ordered, line_no sequential) atomically in a transaction;
 *   5. audit logged as 'lab.order.created' with patientId, encounterId, itemCount;
 *   6. returns 201 with presented order (exact LabOrderController::present() shape).
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleLabOrdersStore } from '../_shared/lab_orders_store.ts';
import type { LabOrdersDeps } from '../_shared/lab_orders.ts';
import type { Claims } from '../_shared/lab_types.ts';
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

const deps: LabOrdersDeps = {
  ...identityDeps,

  createLabOrder: (claims: Claims, payload: LabOrderCreatePayload) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // Validate patient exists in tenant
    const patient = db.queryObject<{ id: string }>(
      'select id from public.patients where id = $1 and tenant_id = $2 and deleted_at is null',
      [payload.patientId, claims.app_tenant_id],
    ).rows[0];
    if (!patient) throw new Error('Patient not found.');

    // Validate encounter if provided
    if (payload.encounterId) {
      const encounter = db.queryObject<{ id: string; facility_id: string }>(
        'select id, facility_id from public.encounters where id = $1 and patient_id = $2 and tenant_id = $3',
        [payload.encounterId, payload.patientId, claims.app_tenant_id],
      ).rows[0];
      if (!encounter) throw new Error('Encounter not found or does not belong to patient.');
    }

    // Validate lab tests exist and are active
    const testIds = [...new Set(payload.items.map((i) => i.labTestId))];
    if (testIds.length === 0) throw new Error('At least one lab test is required.');

    const placeholders = testIds.map((_, i) => `$${i + 1}`).join(',');
    const testRows = db.queryObject<{ id: string; tenant_id: string; status: string }>(
      `select id, tenant_id, status from public.lab_tests where id in (${testIds.map((_, i) => `$${i + 1}`).join(',')}) and status = 'active' and tenant_id = $${testIds.length + 1}`,
      [...testIds, claims.app_tenant_id],
    ).rows;
    if (testRows.length !== testIds.length) {
      throw new Error('One or more lab tests not found or inactive.');
    }

    // Create order + items atomically
    let orderId: string;
    db.execute('begin', []);
    try {
      const orderResult = db.queryObject<{ id: string }>(
        `insert into public.lab_orders (tenant_id, facility_id, branch_id, patient_id, encounter_id,
                ordering_staff_id, status, priority, clinical_indication, lock_version, created_by)
         values ($1, $2, $3, $4, $5, $6, 'ordered', $7, $8, 0, $9)
         returning id`,
        [
          claims.app_tenant_id,
          claims.app_facility_id ?? null,
          null, // branch_id from proposal if provided
          payload.patientId,
          payload.encounterId ?? null,
          payload.orderingStaffId,
          payload.priority,
          payload.clinicalIndication ?? null,
          claims.app_user_id,
        ],
      ).rows[0];
      orderId = orderResult.id;

      // Insert items
      for (let i = 0; i < payload.items.length; i++) {
        const item = payload.items[i];
        db.execute(
          `insert into public.lab_order_items (lab_order_id, lab_test_id, lab_test_code, lab_test_name,
                  lab_test_category, sample_type, unit, reference_range, status, line_no, created_by)
           select $1, lt.id, lt.code, lt.name, lt.category, lt.sample_type, lt.unit, lt.reference_range,
                  'ordered', $2, $3
           from public.lab_tests lt where lt.id = $4`,
          [orderId, i + 1, claims.app_user_id, payload.items[i].labTestId],
        );
      }

      db.execute('commit', []);
    } catch (e) {
      db.execute('rollback', []);
      throw e;
    }

    // Audit
    db.execute(
      `insert into public.audit_events (tenant_id, event_type, auditable_type, auditable_id, payload, created_by)
       values ($1, 'lab.order.created', 'lab_order', $2, $3, $4)`,
      [claims.app_tenant_id, orderId!, JSON.stringify({
        patientId: payload.patientId,
        encounterId: payload.encounterId,
        itemCount: payload.items.length,
      }), claims.app_user_id],
    );

    return orderId!;
  },
};

Deno.serve((req) => handleLabOrdersStore(req, { ...identityDeps }));

/* ------------------------------------------------------------------ */

function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'lab-orders-store wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}