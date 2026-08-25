import { api, type RequestOptions } from './client';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });
import type {
  AdminService, AdminStaff, AdminUser, Department, Facility, FacilitySetting, HospitalBranding, Medication, Organization, Permission, Role,
} from './types';

export const adminOrgsApi = {
  list: () => api.request<Organization[]>('/api/v1/organizations'),
  show: (id: string) => api.request<Organization>(`/api/v1/organizations/${id}`),
};

export const adminFacilitiesApi = {
  list: (orgId: string) => api.request<Facility[]>(`/api/v1/organizations/${orgId}/facilities`),
  show: (id: string) => api.request<Facility>(`/api/v1/facilities/${id}`),
  create: (orgId: string, payload: { name: string; code: string; timezone?: string }) =>
    api.request<Facility>(`/api/v1/organizations/${orgId}/facilities`, { method: 'POST', body: payload }),
};

export const adminUsersApi = {
  list: () => api.request<AdminUser[]>('/api/v1/users'),
  create: (orgId: string, payload: { email: string; password: string; roleCode: string; facilityId?: string }) =>
    api.request<AdminUser>(`/api/v1/organizations/${orgId}/users`, { method: 'POST', body: payload }),
  grantRole: (orgId: string, userId: string, payload: { roleCode: string; facilityId?: string }) =>
    api.request<void>(`/api/v1/organizations/${orgId}/users/${userId}/assignments`, { method: 'POST', body: payload }),
  revokeRole: (orgId: string, userId: string, assignmentId: string) =>
    api.request<void>(`/api/v1/organizations/${orgId}/users/${userId}/assignments/${assignmentId}`, { method: 'DELETE' }),
};

export const adminRolesApi = {
  list: (scopeType?: string) => {
    const qs = scopeType ? `?filter[scopeType]=${scopeType}` : '';
    return api.request<Role[]>(`/api/v1/roles${qs}`);
  },
};

export const adminPermissionsApi = {
  list: () => api.request<Permission[]>('/api/v1/permissions'),
};

export const adminStaffApi = {
  list: (orgId: string, facilityId?: string | null) =>
    api.request<AdminStaff[]>(`/api/v1/organizations/${orgId}/staff`, opt(facilityId)),
  show: (id: string) => api.request<AdminStaff>(`/api/v1/staff/${id}`),
  create: (orgId: string, payload: {
    facilityId: string; employeeCode: string; fullName: string;
    designation?: string; departmentId?: string; userId?: string;
    licenseNumber?: string; status?: string; hireDate?: string;
  }) => api.request<AdminStaff>(`/api/v1/organizations/${orgId}/staff`, { method: 'POST', body: payload }),
  update: (id: string, payload: Partial<{ fullName: string; designation: string; departmentId: string; status: string }>) =>
    api.request<AdminStaff>(`/api/v1/staff/${id}`, { method: 'PATCH', body: payload }),
};

export const adminDepartmentsApi = {
  list: (orgId: string, facilityId?: string | null) =>
    api.request<Department[]>(`/api/v1/organizations/${orgId}/departments`, opt(facilityId)),
  show: (id: string) => api.request<Department>(`/api/v1/departments/${id}`),
  create: (orgId: string, payload: {
    name: string; code: string; facilityId?: string; branchId?: string;
    parentDepartmentId?: string; departmentType?: string; description?: string;
    phone?: string; location?: string; operatingHours?: Array<{ day: string; open: string; close: string }>;
    appointmentAvailability?: Record<string, unknown>; queueSettings?: Record<string, unknown>;
    responsibleRoles?: string[]; sortOrder?: number;
  }) =>
    api.request<Department>(`/api/v1/organizations/${orgId}/departments`, { method: 'POST', body: payload }),
  update: (id: string, payload: Partial<{
    name: string; code: string; status: string; departmentType: string;
    description: string; phone: string; location: string;
    operatingHours: Array<{ day: string; open: string; close: string }>;
    appointmentAvailability: Record<string, unknown>; queueSettings: Record<string, unknown>;
    responsibleRoles: string[]; sortOrder: number;
  }>) =>
    api.request<Department>(`/api/v1/departments/${id}`, { method: 'PATCH', body: payload }),
  remove: (id: string) => api.request<void>(`/api/v1/departments/${id}`, { method: 'DELETE' }),
};

export const adminServicesApi = {
  list: (orgId: string, facilityId?: string | null) =>
    api.request<AdminService[]>(`/api/v1/organizations/${orgId}/services`, opt(facilityId)),
  show: (id: string) => api.request<AdminService>(`/api/v1/services/${id}`),
  create: (orgId: string, payload: {
    name: string; code: string; serviceType: string; facilityId?: string;
    departmentId?: string; defaultDurationMinutes?: number; defaultChargeMinor?: number;
  }) => api.request<AdminService>(`/api/v1/organizations/${orgId}/services`, { method: 'POST', body: payload }),
  update: (id: string, payload: Partial<{ name: string; code: string; serviceType: string; status: string }>) =>
    api.request<AdminService>(`/api/v1/services/${id}`, { method: 'PATCH', body: payload }),
  remove: (id: string) => api.request<void>(`/api/v1/services/${id}`, { method: 'DELETE' }),
};

export const adminMedicationsApi = {
  list: (orgId: string, facilityId?: string | null) =>
    api.request<Medication[]>(`/api/v1/organizations/${orgId}/medications`, opt(facilityId)),
  create: (orgId: string, payload: {
    code: string; genericName: string; strength: string; unit: string;
    priceMinor: number; facilityId: string; brandName?: string; form?: string;
    currency?: string; isControlled?: boolean;
  }) => api.request<Medication>(`/api/v1/organizations/${orgId}/medications`, { method: 'POST', body: payload }),
};

export const adminFacilitySettingsApi = {
  list: (facilityId: string) =>
    api.request<Record<string, FacilitySetting>>(`/api/v1/facilities/${facilityId}/settings`),
  update: (facilityId: string, settings: Record<string, unknown>) =>
    api.request<Record<string, FacilitySetting>>(`/api/v1/facilities/${facilityId}/settings`, { method: 'PUT', body: { settings } }),
  remove: (facilityId: string, key: string) =>
    api.request<void>(`/api/v1/facilities/${facilityId}/settings/${encodeURIComponent(key)}`, { method: 'DELETE' }),
};

export const modulesApi = {
  catalog: () => api.request<{ modules: Array<{ code: string; name: string; description: string }> }>('/api/v1/modules/catalog'),
  enabled: () => api.request<{ modules: Array<{ code: string; name: string; enabled: boolean }> }>('/api/v1/modules/enabled'),
  check: (code: string) => api.request<{ module: string; enabled: boolean }>(`/api/v1/modules/${code}/check`),
};

export const hospitalBrandingApi = {
  get: (facilityId: string) =>
    api.request<{ branding: HospitalBranding | null; defaults: Record<string, unknown> }>(
      `/api/v1/facilities/${facilityId}/branding`,
    ),
  update: (facilityId: string, data: Partial<HospitalBranding>) =>
    api.request<{ branding: HospitalBranding }>(
      `/api/v1/facilities/${facilityId}/branding`,
      { method: 'PUT', body: data },
    ),
  forDocument: (facilityId: string) =>
    api.request<HospitalBranding>(
      `/api/v1/facilities/${facilityId}/branding/document`,
    ),
};

export const numberingApi = {
  types: () => api.request<Array<{ type: string; label: string }>>('/api/v1/numbering/types'),
  list: () => api.request<Array<{
    id: string; documentType: string; prefix: string; sequenceLength: number;
    dateFormat: string | null; resetPolicy: string; includeFacility: boolean;
    separator: string; isActive: boolean;
  }>>('/api/v1/numbering'),
  create: (payload: {
    documentType: string; prefix: string; sequenceLength?: number;
    dateFormat?: string | null; resetPolicy?: string; includeFacility?: boolean;
    separator?: string;
  }) => api.request<{ id: string }>(
    '/api/v1/numbering', { method: 'POST', body: payload },
  ),
  update: (id: string, payload: Partial<{
    prefix: string; sequenceLength: number; dateFormat: string | null;
    resetPolicy: string; includeFacility: boolean; separator: string; isActive: boolean;
  }>) => api.request<{ id: string }>(
    `/api/v1/numbering/${id}`, { method: 'PUT', body: payload },
  ),
  preview: (id: string) => api.request<{ preview: string }>(
    `/api/v1/numbering/${id}/preview`),
  generate: (id: string, facilityId?: string) => api.request<{ documentNumber: string }>(
    `/api/v1/numbering/${id}/generate`, { method: 'POST', body: { facilityId } }),
};
