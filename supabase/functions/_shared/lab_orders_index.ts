/**
 * Shared handler for lab-orders-index — pure logic, no Deno/Supabase deps.
 * Called by the thin adapter in supabase/functions/lab-orders-index/index.ts
 */
import type { Claims, LabOrdersIndexParams, LabOrderPresented, SuccessEnvelope, ErrorEnvelope } from './lab_types.ts';
import type { LabOrdersDeps } from './lab_orders.ts';
import { presentLabOrder, presentLabOrderItem, getTenantId, getFacilityId, isPlatform } from './lab_orders.ts';

export interface LabOrdersIndexDeps extends LabOrdersDeps {
  listLabOrders: (claims: Claims, params: LabOrdersIndexParams) => {
    data: LabOrderPresented[];
    total: number;
    page: number;
    perPage: number;
  };
}

export async function handleLabOrdersIndex(
  req: Request,
  deps: LabOrdersIndexDeps,
): Promise<Response> {
  const correlationId = req.headers.get('X-Correlation-ID') ?? crypto.randomUUID();

  try {
    // The authentication + claims derivation happens in the adapter (pipeline.ts)
    // We assume the claims are already validated and available.
    // In the real deployment, the adapter would have already run authenticateRequest.

    // For this thin adapter, we expect the claims to be passed via a header
    // set by the auth middleware, or we re-derive them here.
    // In the real Supabase deployment, the pipeline sets request.jwt.claims.

    // Extract claims from a header (set by the auth middleware in production)
    // For local testing, this would be set by the test harness.
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

    // Validate required claims
    if (!claims.app_tenant_id || claims.app_tenant_id === '') {
      return errorEnvelope('TENANT_REQUIRED', 'Organization context is required.', 400, correlationId);
    }

    // Authorization: lab:view permission check
    // In the real deployment, this is done by the authorize middleware
    // Here we assume the claims already encode the permissions

    // Parse query params
    const url = new URL(req.url);
    const status = url.searchParams.get('status') ?? undefined;
    const patientId = url.searchParams.get('patientId') ?? undefined;
    const page = parseInt(url.searchParams.get('page') ?? '1', 10);
    const perPage = Math.min(100, Math.max(1, parseInt(url.searchParams.get('perPage') ?? '25', 10)));

    // Call the pure logic
    const result = deps.listLabOrders(claims, {
      status,
      patientId,
      page,
      perPage,
    });

    return successEnvelope({
      data: result.data,
      meta: {
        total: result.total,
        page: result.page,
        perPage: result.perPage,
      },
    }, correlationId);
  } catch (err) {
    console.error('[lab-orders-index] Error:', err);
    return errorEnvelope('SERVER_ERROR', 'An unexpected error occurred', 500, correlationId);
  }
}

/* Re-export envelope helpers from lab_types */
import { errorEnvelope, successEnvelope } from './lab_types.ts';