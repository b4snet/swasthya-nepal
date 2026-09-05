/**
 * Shared handler for lab-orders-show — pure logic, no Deno/Supabase deps.
 * Called by the thin adapter in supabase/functions/lab-orders-show/index.ts
 */
import type { Claims, LabOrderPresented, SuccessEnvelope, ErrorEnvelope } from './lab_types.ts';
import type { LabOrdersDeps } from './lab_orders.ts';
import { presentLabOrder, presentLabOrderItem, getTenantId, getFacilityId } from './lab_orders.ts';

export interface LabOrdersShowDeps extends LabOrdersDeps {
  getLabOrder: (claims: Claims, orderId: string) => {
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
    items: Array<{
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
    }>;
  } | null;
}

export async function handleLabOrdersShow(
  req: Request,
  deps: LabOrdersShowDeps,
): Promise<Response> {
  const correlationId = req.headers.get('X-Correlation-ID') ?? crypto.randomUUID();

  try {
    // Extract claims from header (set by auth middleware in production)
    const claimsHeader = req.headers.get('X-Swasthya-Claims');
    if (!claimsHeader) {
      return errorEnvelope('UNAUTHORIZED', 'Missing authentication context', 401, correlationId);
    }

    let claims: Claims;
    try {
      claims = JSON.parse(claimsHeader);
    } catch {
      return errorEnvelope('INVALID_CLAIMS', 'Invalid claims format', 400, correlationId);
    }

    if (!claims.app_tenant_id || claims.app_tenant_id === '') {
      return errorEnvelope('TENANT_REQUIRED', 'Organization context is required.', 400, correlationId);
    }

    // Extract order ID from URL path
    const url = new URL(req.url);
    const pathParts = url.pathname.split('/');
    const orderId = pathParts[pathParts.length - 1];

    if (!orderId) {
      return errorEnvelope('INVALID_REQUEST', 'Missing lab order ID', 400, correlationId);
    }

    // Call the pure logic
    const result = deps.getLabOrder(claims, orderId);

    if (!result) {
      return errorEnvelope('NOT_FOUND', 'Lab order not found.', 404, correlationId);
    }

    // Present the order
    const presented = presentLabOrder(result, result.items.map(presentLabOrderItem));

    return successEnvelope(presented, correlationId);
  } catch (err) {
    console.error('[lab-orders-show] Error:', err);
    return errorEnvelope('SERVER_ERROR', 'An unexpected error occurred', 500, correlationId);
  }
}

/* Re-export envelope helpers from lab_types */
import { errorEnvelope, successEnvelope } from './lab_types.ts';
import { presentLabOrder, presentLabOrderItem } from './lab_orders.ts';