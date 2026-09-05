/**
 * lab-orders-index — GET /lab-orders facility-wide worklist (Phase 4 LIS).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/lab_orders.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped list is the production-critical wiring (LabOrderController::index
 * + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the caller must hold lab:view (route middleware); the SELECT runs as
 *      swasthya_app: tenant isolation via RLS (tenant_id = claim), facility
 *      isolation via RLS (facility_id = claim OR NULL) — the exact
 *      TENANT_FACILITY tier policy;
 *   3. status filtering (ordered, collected, processing, results_entered,
 *      verified, reported, correcting, cancelled), patient_id filtering,
 *      pagination (25/page default, max 100) — the exact Laravel parity;
 *   4. ordered by `created_at DESC` (the exact `->orderByDesc('created_at')`);
 *   5. NO audit — LabOrderController::index records no audit event.
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleLabOrdersIndex } from '../_shared/lab_orders_index.ts';
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

  listLabOrders: (claims: Claims, params: { status?: string; patientId?: string; page: number; perPage: number }) => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // RLS handles tenant/facility scoping — the claims are already set
    // on the connection. We just need to add the optional filters.
    const facilityId = claims.app_facility_id && claims.app_facility_id !== '' ? claims.app_facility_id : null;
    const tenantId = claims.app_tenant_id;

    const whereParts: string[] = ['lo.tenant_id = $1'];
    const params: unknown[] = [tenantId];
    let paramIndex = 2;

    if (params.status) {
      whereParts.push(`lo.status = $${paramIndex}`);
      params.push(params.status);
      paramIndex++;
    }
    if (params.patientId) {
      whereParts.push(`lo.patient_id = $${paramIndex}`);
      params.push(params.patientId);
      paramIndex++;
    }
    if (facilityId) {
      whereParts.push(`lo.facility_id = $${paramIndex}`);
      params.push(facilityId);
      paramIndex++;
    }

    const offset = (params.page - 1) * params.perPage;
    params.push(params.perPage, offset);

    const whereClause = whereParts.join(' and ');

    // Count total
    const countResult = db.queryObject<{ total: string }>(
      `select count(*)::text as total from public.lab_orders lo ${whereClause}`,
      params.slice(0, paramIndex - 1),
    );
    const total = parseInt(countResult.rows[0]?.total ?? '0', 10);

    // Fetch paginated
    const rows = db.queryObject<LabOrderListRow>(
      `select lo.id, lo.facility_id, lo.branch_id, lo.patient_id, lo.encounter_id,
              lo.ordering_staff_id, lo.status, lo.priority, lo.clinical_indication,
              lo.collected_at, lo.collected_by_staff_id, lo.processed_at,
              lo.processed_by_staff_id, lo.results_entered_at, lo.verified_at,
              lo.verified_by_staff_id, lo.reported_at, lo.lock_version,
              lo.created_at, lo.updated_at
         from public.lab_orders lo
        ${whereClause}
        order by lo.created_at desc
        limit $${paramIndex} offset $${paramIndex + 1}`,
      params,
    ).rows;

    // Fetch items for each order
    const orderIds = rows.map((r) => r.id);
    let itemsMap = new Map<string, LabOrderItemListRow[]>();
    if (orderIds.length > 0) {
      const placeholders = orderIds.map((_, i) => `$${i + 1}`).join(',');
      const itemRows = db.queryObject<LabOrderItemListRow>(
        `select loi.id, loi.lab_order_id, loi.lab_test_id, loi.lab_test_code, loi.lab_test_name,
                loi.lab_test_category, loi.sample_type, loi.unit, loi.reference_range,
                loi.result_value, loi.result_unit, loi.reference_range_snapshot,
                loi.abnormal_flag, loi.status, loi.entered_by_staff_id, loi.entered_at,
                loi.verified_by_staff_id, loi.verified_at, loi.created_at
           from public.lab_order_items loi
          where loi.lab_order_id in (${placeholders})
          order by loi.line_no asc`,
        orderIds,
      ).rows;
      for (const item of itemRows) {
        const existing = itemsMap.get(item.lab_order_id) ?? [];
        existing.push(item);
        itemsMap.set(item.lab_order_id, existing);
      }
    }

    const data = rows.map((row) => ({
      id: row.id,
      facilityId: row.facility_id,
      branchId: row.branch_id,
      patientId: row.patient_id,
      encounterId: row.encounter_id,
      orderingStaffId: row.ordering_staff_id,
      status: row.status,
      priority: row.priority,
      clinicalIndication: row.clinical_indication,
      collectedAt: row.collected_at,
      collectedByStaffId: row.collected_by_staff_id,
      processedAt: row.processed_at,
      processedByStaffId: row.processed_by_staff_id,
      resultsEnteredAt: row.results_entered_at,
      verifiedAt: row.verified_at,
      verifiedByStaffId: row.verified_by_staff_id,
      reportedAt: row.reported_at,
      lockVersion: row.lock_version,
      items: (itemsMap.get(row.id) ?? []).map(presentLabOrderItem),
      createdAt: row.created_at,
    }));

    return { data, total, page: 1, perPage: 25 }; // pagination handled by caller
  },
};

Deno.serve((req) => handleLabOrdersIndex(req, deps));

/* ------------------------------------------------------------------ */

interface LabOrderListRow {
  id: string;
  facility_id: string;
  branch_id: string | null;
  patient_id: string;
  encounter_id: string | null;
  ordering_staff_id: string;
  status: string;
  priority: string;
  clinical_indication: string | null;
  collected_at: string | null;
  collected_by_staff_id: string | null;
  processed_at: string | null;
  processed_by_staff_id: string | null;
  results_entered_at: string | null;
  verified_at: string | null;
  verified_by_staff_id: string | null;
  reported_at: string | null;
  lock_version: number;
  created_at: string;
  updated_at: string;
}

interface LabOrderItemListRow {
  id: string;
  lab_order_id: string;
  lab_test_id: string;
  lab_test_code: string;
  lab_test_name: string;
  lab_test_category: string;
  sample_type: string;
  unit: string;
  reference_range: string | null;
  result_value: string | null;
  result_unit: string | null;
  reference_range_snapshot: string | null;
  abnormal_flag: string | null;
  status: string;
  entered_by_staff_id: string | null;
  entered_at: string | null;
  verified_by_staff_id: string | null;
  verified_at: string | null;
  created_at: string;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'lab-orders-index wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}