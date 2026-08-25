import { api, ApiError, type RequestOptions } from './client';
import type {
  Asset, AssetCategory, AssetTransfer, AttendanceRecord, LeaveRequest, LeaveType, MaintenanceSchedule, PayrollExport, Position, PurchaseOrder, PurchaseRequest, Roster, ShiftTemplate, Vendor, WorkOrder,
} from './types';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

function orgUrl(organizationId: string | null | undefined): string {
  if (!organizationId) {
    throw new ApiError('NO_TENANT_CONTEXT', 'Organization context is required for this request.');
  }
  return `/api/v1/organizations/${organizationId}`;
}

export const hrApi = {
  positions: (facilityId?: string | null) =>
    api.request<Position[]>('/api/v1/positions', opt(facilityId)),
  storePosition: (payload: { departmentId: string; code: string; name: string; status?: string }, facilityId?: string | null) =>
    api.request<Position>('/api/v1/positions', { method: 'POST', body: payload, ...opt(facilityId) }),

  shiftTemplates: (facilityId?: string | null) =>
    api.request<ShiftTemplate[]>('/api/v1/shift-templates', opt(facilityId)),
  storeShiftTemplate: (payload: { departmentId: string; code: string; name: string; shiftType: string; startsAt: string; endsAt: string; workingMinutes: number; status?: string }, facilityId?: string | null) =>
    api.request<ShiftTemplate>('/api/v1/shift-templates', { method: 'POST', body: payload, ...opt(facilityId) }),

  rosters: (facilityId?: string | null, date?: string | null) =>
    api.request<Roster[]>(`/api/v1/rosters` + (date ? `?date=${date}` : ''), opt(facilityId)),
  storeRoster: (payload: { staffId: string; shiftTemplateId: string; rosterDate: string; notes?: string }, facilityId?: string | null) =>
    api.request<Roster>('/api/v1/rosters', { method: 'POST', body: payload, ...opt(facilityId) }),
  confirmRoster: (id: string, facilityId?: string | null) =>
    api.request<Roster>(`/api/v1/rosters/${id}/confirm`, { method: 'POST', body: {}, ...opt(facilityId) }),

  attendance: (facilityId?: string | null, date?: string | null) =>
    api.request<AttendanceRecord[]>(`/api/v1/attendance` + (date ? `?date=${date}` : ''), opt(facilityId)),
  storeAttendance: (payload: { staffId: string; attendanceDate: string; clockInAt?: string; clockOutAt?: string; status?: string; source?: string }, facilityId?: string | null) =>
    api.request<AttendanceRecord>('/api/v1/attendance', { method: 'POST', body: payload, ...opt(facilityId) }),

  leaveTypes: (facilityId?: string | null) =>
    api.request<LeaveType[]>('/api/v1/leave-types', opt(facilityId)),
  storeLeaveType: (payload: { code: string; name: string; paidDaysPerYear: number; carryoverDays: number; status?: string }, facilityId?: string | null) =>
    api.request<LeaveType>('/api/v1/leave-types', { method: 'POST', body: payload, ...opt(facilityId) }),
  leaveRequests: (facilityId?: string | null) =>
    api.request<LeaveRequest[]>('/api/v1/leave-requests', opt(facilityId)),
  storeLeaveRequest: (payload: { staffId: string; leaveTypeId: string; startsOn: string; endsOn: string; daysRequested: number; reason?: string }, facilityId?: string | null) =>
    api.request<LeaveRequest>('/api/v1/leave-requests', { method: 'POST', body: payload, ...opt(facilityId) }),
  approveLeaveRequest: (id: string, notes?: string, facilityId?: string | null) =>
    api.request<LeaveRequest>(`/api/v1/leave-requests/${id}/approve`, { method: 'POST', body: { notes }, ...opt(facilityId) }),
  rejectLeaveRequest: (id: string, notes?: string, facilityId?: string | null) =>
    api.request<LeaveRequest>(`/api/v1/leave-requests/${id}/reject`, { method: 'POST', body: { notes }, ...opt(facilityId) }),

  payrollExports: (facilityId?: string | null) =>
    api.request<PayrollExport[]>('/api/v1/payroll-exports', opt(facilityId)),
  generatePayrollExport: (payload: { periodStart: string; periodEnd: string; format?: string }, facilityId?: string | null) =>
    api.request<{ export: PayrollExport; payload: unknown }>('/api/v1/payroll-exports', { method: 'POST', body: payload, ...opt(facilityId) }),
};

export const assetApi = {
  categories: (facilityId?: string | null) =>
    api.request<AssetCategory[]>('/api/v1/asset-categories', opt(facilityId)),
  storeCategory: (payload: { code: string; name: string; status?: string }, facilityId?: string | null) =>
    api.request<AssetCategory>('/api/v1/asset-categories', { method: 'POST', body: payload, ...opt(facilityId) }),

  list: (facilityId?: string | null, lifecycleStatus?: string | null) =>
    api.request<Asset[]>(`/api/v1/assets` + (lifecycleStatus ? `?lifecycleStatus=${lifecycleStatus}` : ''), opt(facilityId)),
  store: (payload: { categoryId: string; name: string; serialNumber?: string; rfidTag?: string; barcode?: string; currentLocationId?: string; purchaseValueMinor?: number; purchaseDate?: string; warrantyUntil?: string }, facilityId?: string | null) =>
    api.request<Asset>('/api/v1/assets', { method: 'POST', body: payload, ...opt(facilityId) }),
  deploy: (id: string, facilityId?: string | null) =>
    api.request<Asset>(`/api/v1/assets/${id}/deploy`, { method: 'POST', body: {}, ...opt(facilityId) }),
  retire: (id: string, facilityId?: string | null) =>
    api.request<Asset>(`/api/v1/assets/${id}/retire`, { method: 'POST', body: {}, ...opt(facilityId) }),
  transfer: (id: string, payload: { toLocationId: string; reason?: string }, facilityId?: string | null) =>
    api.request<Asset>(`/api/v1/assets/${id}/transfer`, { method: 'POST', body: payload, ...opt(facilityId) }),
  transfers: (id: string, facilityId?: string | null) =>
    api.request<AssetTransfer[]>(`/api/v1/assets/${id}/transfers`, opt(facilityId)),

  maintenanceSchedules: (facilityId?: string | null) =>
    api.request<MaintenanceSchedule[]>('/api/v1/maintenance-schedules', opt(facilityId)),
  storeMaintenanceSchedule: (payload: { assetId: string; scheduleType: string; frequencyDays: number; nextDueDate: string; contractRef?: string; status?: string }, facilityId?: string | null) =>
    api.request<MaintenanceSchedule>('/api/v1/maintenance-schedules', { method: 'POST', body: payload, ...opt(facilityId) }),

  workOrders: (facilityId?: string | null) =>
    api.request<WorkOrder[]>('/api/v1/work-orders', opt(facilityId)),
  openWorkOrder: (payload: { assetId: string; description?: string; downtimeStartedAt?: string; maintenanceScheduleId?: string }, facilityId?: string | null) =>
    api.request<{ workOrder: WorkOrder; assetLifecycleStatus: string }>('/api/v1/work-orders', { method: 'POST', body: payload, ...opt(facilityId) }),
  completeWorkOrder: (id: string, payload: { downtimeEndedAt?: string; certificationRef?: string }, facilityId?: string | null) =>
    api.request<{ workOrder: WorkOrder; assetLifecycleStatus: string }>(`/api/v1/work-orders/${id}/complete`, { method: 'POST', body: payload, ...opt(facilityId) }),
  cancelWorkOrder: (id: string, facilityId?: string | null) =>
    api.request<{ workOrder: WorkOrder; assetLifecycleStatus: string }>(`/api/v1/work-orders/${id}/cancel`, { method: 'POST', body: {}, ...opt(facilityId) }),
};

export const procurementApi = {
  vendors: (organizationId: string, facilityId?: string | null) =>
    api.request<Vendor[]>(`${orgUrl(organizationId)}/procurement/vendors`, opt(facilityId)),

  storeVendor: (organizationId: string, payload: { code: string; name: string; taxId?: string; bankDetails?: string }, facilityId?: string | null) =>
    api.request<Vendor>(`${orgUrl(organizationId)}/procurement/vendors`, { method: 'POST', body: payload, ...opt(facilityId) }),

  requests: (organizationId: string, facilityId?: string | null) =>
    api.request<PurchaseRequest[]>(`${orgUrl(organizationId)}/procurement/requests`, opt(facilityId)),

  showRequest: (id: string, facilityId?: string | null) =>
    api.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}`, opt(facilityId)),

  storeRequest: (organizationId: string, payload: { lines: Array<{ medicationId: string; quantity: number; estimatedUnitPriceMinor: number }> }, facilityId?: string | null) =>
    api.request<PurchaseRequest>(`${orgUrl(organizationId)}/procurement/requests`, { method: 'POST', body: payload, ...opt(facilityId) }),

  submitRequest: (id: string, facilityId?: string | null) =>
    api.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}/submit`, { method: 'POST', body: {}, ...opt(facilityId) }),

  approveRequest: (id: string, facilityId?: string | null) =>
    api.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}/approve`, { method: 'POST', body: {}, ...opt(facilityId) }),

  rejectRequest: (id: string, facilityId?: string | null) =>
    api.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}/reject`, { method: 'POST', body: {}, ...opt(facilityId) }),

  orders: (organizationId: string, facilityId?: string | null) =>
    api.request<PurchaseOrder[]>(`${orgUrl(organizationId)}/procurement/orders`, opt(facilityId)),

  storeOrder: (organizationId: string, payload: { vendorId: string; expectedDelivery?: string; lines: Array<{ medicationId: string; quantityOrdered: number; unitPriceMinor: number }> }, facilityId?: string | null) =>
    api.request<PurchaseOrder>(`${orgUrl(organizationId)}/procurement/orders`, { method: 'POST', body: payload, ...opt(facilityId) }),

  confirmOrder: (id: string, facilityId?: string | null) =>
    api.request<PurchaseOrder>(`/api/v1/purchase-orders/${id}/confirm`, { method: 'POST', body: {}, ...opt(facilityId) }),

  closeOrder: (id: string, facilityId?: string | null) =>
    api.request<PurchaseOrder>(`/api/v1/purchase-orders/${id}/close`, { method: 'POST', body: {}, ...opt(facilityId) }),

  receiveGoods: (id: string, payload: { lines: Array<{ purchaseOrderLineId: string; quantityReceived: number }> }, facilityId?: string | null) =>
    api.request<PurchaseOrder>(`/api/v1/purchase-orders/${id}/goods-receipts`, { method: 'POST', body: payload, ...opt(facilityId) }),
};
