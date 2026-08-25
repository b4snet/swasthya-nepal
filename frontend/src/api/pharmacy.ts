import { api, ApiError, type RequestOptions } from './client';
import type {
  InventoryAdjustmentRequest, InventoryItem, PharmacyPrescription, StockBatch,
} from './types';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

function orgUrl(organizationId: string | null | undefined): string {
  if (!organizationId) {
    throw new ApiError('NO_TENANT_CONTEXT', 'Organization context is required for this request.');
  }
  return `/api/v1/organizations/${organizationId}`;
}

export const pharmacyApi = {
  list: (params: { status?: string; search?: string; facilityId?: string | null; perPage?: number } = {}) => {
    const qs = new URLSearchParams();
    if (params.status) qs.set('status', params.status);
    if (params.search) qs.set('search', params.search);
    if (params.facilityId) qs.set('facilityId', params.facilityId);
    if (params.perPage) qs.set('perPage', String(params.perPage));
    const q = qs.toString();
    return api.request<{ data: PharmacyPrescription[]; current_page: number; last_page: number; per_page: number; total: number }>(
      `/api/v1/prescriptions?${q}`,
    );
  },
  showPrescription: (id: string, facilityId?: string | null) =>
    api.request<PharmacyPrescription>(`/api/v1/prescriptions/${id}`, opt(facilityId)),

  verify: (id: string, facilityId?: string | null) =>
    api.request<PharmacyPrescription>(`/api/v1/prescriptions/${id}/verify`, { method: 'POST', body: {}, ...opt(facilityId) }),

  dispense: (id: string, payload: { batchSelections?: Array<{ lineId: string; batchId: string; quantity: number }> }, facilityId?: string | null) =>
    api.request<PharmacyPrescription>(`/api/v1/prescriptions/${id}/dispense`, { method: 'POST', body: payload, ...opt(facilityId) }),

  returnLine: (lineId: string, payload: { quantityMinor?: number; reason: string }, facilityId?: string | null) =>
    api.request<PharmacyPrescription>(`/api/v1/prescription-lines/${lineId}/return`, { method: 'POST', body: payload, ...opt(facilityId) }),

  dualVerify: (lineId: string, facilityId?: string | null) =>
    api.request<void>(`/api/v1/prescription-lines/${lineId}/dual-verify`, { method: 'POST', body: {}, ...opt(facilityId) }),
};

export const inventoryApi = {
  list: (organizationId: string, facilityId?: string | null) =>
    api.request<InventoryItem[]>(`${orgUrl(organizationId)}/inventory`, opt(facilityId)),

  batches: (itemId: string, facilityId?: string | null) =>
    api.request<StockBatch[]>(`/api/v1/inventory-items/${itemId}/batches`, opt(facilityId)),

  store: (organizationId: string, payload: { medicationId: string; quantityOnHand?: number; reorderLevel?: number }, facilityId?: string | null) =>
    api.request<InventoryItem>(`${orgUrl(organizationId)}/inventory`, { method: 'POST', body: payload, ...opt(facilityId) }),

  adjust: (itemId: string, payload: { quantityDelta: number; reason: string }, facilityId?: string | null) =>
    api.request<InventoryItem>(`/api/v1/inventory-items/${itemId}/adjust`, { method: 'POST', body: payload, ...opt(facilityId) }),

  reorderAlerts: (organizationId: string, facilityId?: string | null) =>
    api.request<InventoryItem[]>(`${orgUrl(organizationId)}/reorder-alerts`, opt(facilityId)),

  storeAdjustmentRequest: (itemId: string, payload: { quantityDelta: number }, facilityId?: string | null) =>
    api.request<InventoryAdjustmentRequest>(`/api/v1/inventory-items/${itemId}/adjustment-requests`, { method: 'POST', body: payload, ...opt(facilityId) }),

  adjustmentRequests: (itemId: string, facilityId?: string | null) =>
    api.request<InventoryAdjustmentRequest[]>(`/api/v1/inventory-items/${itemId}/adjustment-requests`, opt(facilityId)),

  approveAdjustment: (requestId: string, facilityId?: string | null) =>
    api.request<InventoryAdjustmentRequest>(`/api/v1/inventory-adjustment-requests/${requestId}/approve`, { method: 'POST', body: {}, ...opt(facilityId) }),

  rejectAdjustment: (requestId: string, facilityId?: string | null) =>
    api.request<InventoryAdjustmentRequest>(`/api/v1/inventory-adjustment-requests/${requestId}/reject`, { method: 'POST', body: {}, ...opt(facilityId) }),

  transfer: (payload: { fromInventoryItemId: string; toInventoryItemId: string; quantity: number; reason: string }, facilityId?: string | null) =>
    api.request<void>('/api/v1/inventory-transfers', { method: 'POST', body: payload, ...opt(facilityId) }),
};
