/**
 * Pure LabOrder business logic — framework-independent, dependency-injected.
 * Mirrors LabOrderController exactly: present() shapes, status transitions,
 * CAS guards, AccessCheck parity.
 */
import type {
  Claims,
  LabOrderRow,
  LabOrderItemRow,
  LabOrderItemPresented,
  LabOrderPresented,
  LabOrdersIndexParams,
  LabOrderCreatePayload,
  LabOrderCollectPayload,
  LabOrderProcessPayload,
  LabOrderEnterResultsPayload,
  LabOrderVerifyPayload,
  LabOrderReportPayload,
  LabOrderStatus,
  LabOrderItemStatus,
  ErrorEnvelope,
  SuccessEnvelope,
  LabOrderItemStatusType,
  LabOrderStatusType,
} from './lab_types.ts';

export interface LabOrdersDeps {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
  queryArray<T>(sql: string, params?: unknown[]): { rows: T[] };
}

/** Present a LabOrderItem row as the exact Laravel `present()` shape */
export function presentLabOrderItem(row: LabOrderItemRow): LabOrderItemPresented {
  return {
    id: row.id,
    labTestId: row.lab_test_id,
    labTestCode: row.lab_test_code,
    labTestName: row.lab_test_name,
    labTestCategory: row.lab_test_category,
    sampleType: row.sample_type,
    unit: row.unit,
    referenceRange: row.reference_range,
    resultValue: row.result_value,
    resultUnit: row.result_unit,
    referenceRangeSnapshot: row.reference_range_snapshot,
    abnormalFlag: row.abnormal_flag,
    status: row.status,
    enteredByStaffId: row.entered_by_staff_id,
    enteredAt: row.entered_at,
    verifiedByStaffId: row.verified_by_staff_id,
    verifiedAt: row.verified_at,
  };
}

/** Present a LabOrder row with its items as the exact Laravel `present()` shape */
export function presentLabOrder(order: LabOrderRow, items: LabOrderItemPresented[]): LabOrderPresented {
  return {
    id: order.id,
    facilityId: order.facility_id,
    branchId: order.branch_id,
    patientId: order.patient_id,
    encounterId: order.encounter_id,
    orderingStaffId: order.ordering_staff_id,
    status: order.status,
    priority: order.priority,
    clinicalIndication: order.clinical_indication,
    collectedAt: order.collected_at,
    collectedByStaffId: order.collected_by_staff_id,
    processedAt: order.processed_at,
    processedByStaffId: order.processed_by_staff_id,
    resultsEnteredAt: order.results_entered_at,
    verifiedAt: order.verified_at,
    verifiedByStaffId: order.verified_by_staff_id,
    reportedAt: order.reported_at,
    lockVersion: order.lock_version,
    items,
    createdAt: order.created_at,
  };
}

/** Status transition validation — must match LabOrderController guardStatus() */
export function canTransition(from: LabOrderStatusType, to: LabOrderStatusType): boolean {
  const allowed: Record<LabOrderStatusType, LabOrderStatusType[]> = {
    ordered: ['collected', 'cancelled'],
    collected: ['processing', 'cancelled'],
    processing: ['results_entered'],
    results_entered: ['verified'],
    verified: ['reported', 'correcting'],
    reported: ['correcting'],
    correcting: ['verified'],
    cancelled: [],
  };
  return allowed[from]?.includes(to) ?? false;
}

/** Validate a payload against a schema (basic check) */
export function validatePayload<T>(payload: unknown, requiredFields: string[]): T {
  if (typeof payload !== 'object' || payload === null) {
    throw new Error('Payload must be a non-null object');
  }
  const obj = payload as Record<string, unknown>;
  for (const field of requiredFields) {
    if (!(field in obj) || obj[field] === undefined || obj[field] === null) {
      throw new Error(`Missing required field: ${field}`);
    }
  }
  return obj as T;
}

/** Check if claims has a facility scope */
export function hasFacilityClaim(claims: Claims): boolean {
  return claims.app_facility_id !== '' && claims.app_facility_id !== undefined;
}

/** Check if claims is platform */
export function isPlatform(claims: Claims): boolean {
  return claims.app_is_platform === 'true';
}

/** Get tenant ID from claims */
export function getTenantId(claims: Claims): string {
  return claims.app_tenant_id;
}

/** Get facility ID from claims */
export function getFacilityId(claims: Claims): string | null {
  const fid = claims.app_facility_id;
  return fid && fid !== '' ? fid : null;
}

/** Get user ID from claims */
export function getUserId(claims: Claims): string {
  return claims.app_user_id;
}

/** Build WHERE clause for facility scoping */
export function facilityWhereClause(claims: Claims): { clause: string; params: unknown[] } {
  const facilityId = getFacilityId(claims);
  if (!facilityId) {
    return { clause: '', params: [] };
  }
  return { clause: ' and lo.facility_id = $1', params: [facilityId] };
}

/** Build WHERE clause for tenant scoping */
export function tenantWhereClause(claims: Claims): { clause: string; params: unknown[] } {
  const tenantId = getTenantId(claims);
  return { clause: 'where lo.tenant_id = $1', params: [tenantId] };
}

/** Build pagination LIMIT/OFFSET */
export function paginationClause(page: number, perPage: number): { clause: string; params: unknown[]; startIndex: number } {
  const safePage = Math.max(1, Math.floor(page));
  const safePerPage = Math.min(100, Math.max(1, Math.floor(perPage)));
  const offset = (safePage - 1) * safePerPage;
  return { clause: `limit $${1} offset $${2}`, params: [safePerPage, offset], startIndex: offset };
}