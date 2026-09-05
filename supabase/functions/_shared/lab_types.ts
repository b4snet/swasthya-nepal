/**
 * Shared Lab/LIS types for Supabase Edge Functions.
 * Mirrors the exact Laravel `present()` shapes from LabOrderController,
 * LabTestController, CriticalValueEventController.
 */
export interface Claims {
  app_user_id: string;
  app_tenant_id: string;
  app_facility_id: string;
  app_branch_id: string;
  app_is_platform: string;
}

export interface LabOrderItemRow {
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

export interface LabOrderRow {
  id: string;
  tenant_id: string;
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

export interface SpecimenRow {
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

export interface CriticalValueEventRow {
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

export interface LabTestRow {
  id: string;
  tenant_id: string;
  facility_id: string | null;
  code: string;
  name: string;
  category: string;
  sample_type: string;
  unit: string;
  reference_range: string | null;
  method: string | null;
  status: string;
  lock_version: number;
  created_at: string;
}

export interface LabOrderItemPresented {
  id: string;
  labTestId: string;
  labTestCode: string;
  labTestName: string;
  labTestCategory: string;
  sampleType: string;
  unit: string;
  referenceRange: string | null;
  resultValue: string | null;
  resultUnit: string | null;
  referenceRangeSnapshot: string | null;
  abnormalFlag: string | null;
  status: string;
  enteredByStaffId: string | null;
  enteredAt: string | null;
  verifiedByStaffId: string | null;
  verifiedAt: string | null;
}

export interface LabOrderPresented {
  id: string;
  facilityId: string;
  branchId: string | null;
  patientId: string;
  encounterId: string | null;
  orderingStaffId: string;
  status: string;
  priority: string;
  clinicalIndication: string | null;
  collectedAt: string | null;
  collectedByStaffId: string | null;
  processedAt: string | null;
  processedByStaffId: string | null;
  resultsEnteredAt: string | null;
  verifiedAt: string | null;
  verifiedByStaffId: string | null;
  reportedAt: string | null;
  lockVersion: number;
  items: LabOrderItemPresented[];
  createdAt: string;
}

export interface SpecimenPresented {
  id: string;
  labOrderId: string;
  labOrderItemId: string | null;
  specimenType: string;
  status: string;
  collectedAt: string | null;
  collectedByStaffId: string | null;
  accessionedAt: string | null;
  accessionedByStaffId: string | null;
  processedAt: string | null;
  processedByStaffId: string | null;
  completedAt: string | null;
  rejectedAt: string | null;
  rejectedByStaffId: string | null;
  rejectionReason: string | null;
  lockVersion: number;
  createdAt: string;
}

export interface CriticalValueEventPresented {
  id: string;
  labOrderItemId: string;
  resultValue: string;
  thresholdValue: string;
  direction: string;
  status: string;
  triggeredAt: string;
  acknowledgedAt: string | null;
  acknowledgedByStaffId: string | null;
  escalatedAt: string | null;
  escalatedByStaffId: string | null;
  lockVersion: number;
  createdAt: string;
}

export interface LabTestPresented {
  id: string;
  facilityId: string | null;
  code: string;
  name: string;
  category: string;
  sampleType: string;
  unit: string;
  referenceRange: string | null;
  method: string | null;
  status: string;
  lockVersion: number;
  createdAt: string;
}

export interface PaginationParams {
  page: number;
  perPage: number;
}

export interface LabOrdersIndexParams extends PaginationParams {
  status?: string;
  patientId?: string;
}

export interface SpecimensIndexParams extends PaginationParams {
  status?: string;
  labOrderId?: string;
}

export interface LabTestUpdatePayload {
  name?: string;
  category?: string;
  sampleType?: string;
  unit?: string;
  referenceRange?: string | null;
  method?: string | null;
  status?: string;
}

export interface LabOrderCreatePayload {
  patientId: string;
  encounterId?: string | null;
  orderingStaffId: string;
  priority: string;
  clinicalIndication?: string | null;
  items: Array<{
    labTestId: string;
  }>;
}

export interface LabOrderCollectPayload {
  collectedByStaffId: string;
  items: Array<{
    labOrderItemId: string;
  }>;
}

export interface LabOrderProcessPayload {
  processedByStaffId: string;
  items: Array<{
    labOrderItemId: string;
  }>;
}

export interface LabOrderEnterResultsPayload {
  enteredByStaffId: string;
  items: Array<{
    labOrderItemId: string;
    resultValue: string;
    resultUnit?: string;
    abnormalFlag?: string | null;
  }>;
}

export interface LabOrderVerifyPayload {
  verifiedByStaffId: string;
  items: Array<{
    labOrderItemId: string;
  }>;
}

export interface LabOrderReportPayload {
  reportedByStaffId: string;
  items: Array<{
    labOrderItemId: string;
  }>;
}

export interface CriticalValueAcknowledgePayload {
  acknowledgedByStaffId: string;
}

export interface CriticalValueEscalatePayload {
  escalatedByStaffId: string;
}

export interface LabTestCreatePayload {
  code: string;
  name: string;
  category: string;
  sampleType: string;
  unit: string;
  referenceRange?: string | null;
  method?: string | null;
  status?: string;
}

export interface LabTestUpdatePayload {
  name?: string;
  category?: string;
  sampleType?: string;
  unit?: string;
  referenceRange?: string | null;
  method?: string | null;
  status?: string;
}

/** Status constants — must match Laravel LabOrder::STATUS_*, Specimen::STATUS_*, CriticalValueEvent::STATUS_* */
export const LabOrderStatus = {
  ORDERED: 'ordered',
  COLLECTED: 'collected',
  PROCESSING: 'processing',
  RESULTS_ENTERED: 'results_entered',
  VERIFIED: 'verified',
  REPORTED: 'reported',
  CORRECTING: 'correcting',
  CANCELLED: 'cancelled',
} as const;

export const SpecimenStatus = {
  COLLECTED: 'collected',
  ACCESSIONED: 'accessioned',
  PROCESSING: 'processing',
  COMPLETED: 'completed',
  REJECTED: 'rejected',
} as const;

export const CriticalValueEventStatus = {
  TRIGGERED: 'triggered',
  ACKNOWLEDGED: 'acknowledged',
  ESCALATED: 'escalated',
} as const;

export const LabOrderItemStatus = {
  ORDERED: 'ordered',
  COLLECTED: 'collected',
  PROCESSING: 'processing',
  RESULTS_ENTERED: 'results_entered',
  VERIFIED: 'verified',
  REPORTED: 'reported',
  CORRECTING: 'correcting',
} as const;

export type LabOrderStatusType = typeof LabOrderStatus[keyof typeof LabOrderStatus];
export type SpecimenStatusType = typeof SpecimenStatus[keyof typeof SpecimenStatus];
export type CriticalValueEventStatusType = typeof CriticalValueEventStatus[keyof typeof CriticalValueEventStatus];
export type LabOrderItemStatusType = typeof LabOrderItemStatus[keyof typeof LabOrderItemStatus];

/** Error envelope shape */
export interface ErrorEnvelope {
  error: {
    code: string;
    message: string;
    correlationId: string;
  };
}

export interface SuccessEnvelope<T> {
  data: T;
  correlationId: string;
}

/** Build a standard error envelope */
export function errorEnvelope(code: string, message: string, status: number, correlationId: string): Response {
  const body: ErrorEnvelope = { error: { code, message, correlationId } };
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', 'X-Correlation-ID': correlationId },
  });
}

/** Build a standard success envelope */
export function successEnvelope<T>(data: T, correlationId: string, status = 200): Response {
  const body: SuccessEnvelope<T> = { data, correlationId };
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', 'X-Correlation-ID': correlationId },
  });
}

/** Generate a correlation ID from request header or mint new */
export function correlationId(req: Request): string {
  return req.headers.get('X-Correlation-ID') ?? crypto.randomUUID();
}

/** Validate UUID format */
export function isValidUuid(uuid: string): boolean {
  const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  return uuidRegex.test(uuid);
}

/** Validate status value against allowed enum */
export function isValidStatus(status: string, allowed: readonly string[]): boolean {
  return allowed.includes(status);
}