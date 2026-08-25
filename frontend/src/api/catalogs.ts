import { api, ApiError, type RequestOptions } from './client';
import type {
  Medication, Service, Staff,
} from './types';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

function orgUrl(organizationId: string | null | undefined): string {
  if (!organizationId) {
    throw new ApiError('NO_TENANT_CONTEXT', 'Organization context is required for this request.');
  }
  return `/api/v1/organizations/${organizationId}`;
}

export const catalogsApi = {
  staff: (organizationId: string, facilityId?: string | null) =>
    api.request<Staff[]>(`${orgUrl(organizationId)}/staff`, opt(facilityId)),

  services: (organizationId: string, facilityId?: string | null) =>
    api.request<Service[]>(`${orgUrl(organizationId)}/services`, opt(facilityId)),

  medications: (organizationId: string, facilityId?: string | null) =>
    api.request<Medication[]>(`${orgUrl(organizationId)}/medications`, opt(facilityId)),
};
