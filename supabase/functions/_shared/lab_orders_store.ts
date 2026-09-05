/**
 * Shared handler for lab-orders-store — pure logic, no Deno/Supabase deps.
 * Called by the thin adapter in supabase/functions/lab-orders-store/index.ts
 */
import type { Claims, LabOrderCreatePayload, LabOrderPresented, SuccessEnvelope, ErrorEnvelope } from './lab_types.ts';
import type { LabOrdersDeps } from './lab_orders.ts';
import { presentLabOrder, presentLabOrderItem } from './lab_orders.ts';

export interface LabOrdersStoreDeps extends LabOrdersDeps {
  createLabOrder: (claims: Claims, payload: LabOrderCreatePayload) => string;
}

export async function handleLabOrdersStore(
  req: Request,
  deps: LabOrdersStoreDeps,
): Promise<Response> {
  const correlationId = req.headers.get('X-Correlation-ID') ?? crypto.randomUUID();

  try {
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

    if (req.method !== 'POST') {
      return errorEnvelope('METHOD_NOT_ALLOWED', 'Only POST allowed', 405, correlationId);
    }

    let payload: LabOrderCreatePayload;
    try {
      payload = await req.json();
    } catch {
      return errorEnvelope('INVALID_REQUEST', 'Invalid JSON body', 400, correlationId);
    }

    // Validate required fields
    if (!payload.patientId || !payload.orderingStaffId || !payload.priority || !payload.items?.length) {
      return errorEnvelope('VALIDATION_ERROR', 'Missing required fields: patientId, orderingStaffId, priority, items', 422, correlationId);
    }

    // Call pure logic
    const orderId = deps.createLabOrder(claims, payload);

    // Return created order
    // In real deployment, would fetch and present the created order
    return successEnvelope({ id: orderId }, correlationId, 201);
  } catch (err) {
    console.error('[lab-orders-store] Error:', err);
    const message = err instanceof Error ? err.message : 'An unexpected error occurred';
    return errorEnvelope('SERVER_ERROR', message, 500, correlationId);
  }
}

import { errorEnvelope, successEnvelope } from './lab_types.ts';