import { api, ApiError, type RequestOptions } from './client';
import type {
  CriticalValueEvent, LabOrder, LabTest, Modality, RadiologyOrder, RadiologyStudy,
} from './types';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

function orgUrl(organizationId: string | null | undefined): string {
  if (!organizationId) {
    throw new ApiError('NO_TENANT_CONTEXT', 'Organization context is required for this request.');
  }
  return `/api/v1/organizations/${organizationId}`;
}

export const labTestsApi = {
  list: (organizationId: string, facilityId?: string | null) =>
    api.request<LabTest[]>(`${orgUrl(organizationId)}/lab-tests`, opt(facilityId)),
};

export const labOrdersApi = {
  forEncounter: (encounterId: string, facilityId?: string | null) =>
    api.request<LabOrder[]>(`/api/v1/encounters/${encounterId}/lab-orders`, opt(facilityId)),

  forPatient: (patientId: string, facilityId?: string | null) =>
    api.request<LabOrder[]>(`/api/v1/patients/${patientId}/lab-orders`, opt(facilityId)),

  show: (orderId: string, facilityId?: string | null) =>
    api.request<LabOrder>(`/api/v1/lab-orders/${orderId}`, opt(facilityId)),

  store: (encounterId: string, payload: { testIds: string[]; priority?: string; clinicalIndication?: string }, facilityId?: string | null) =>
    api.request<LabOrder>(`/api/v1/encounters/${encounterId}/lab-orders`, { method: 'POST', body: payload, ...opt(facilityId) }),

  collect: (orderId: string, facilityId?: string | null) =>
    api.request<LabOrder>(`/api/v1/lab-orders/${orderId}/collect`, { method: 'POST', body: {}, ...opt(facilityId) }),

  process: (orderId: string, facilityId?: string | null) =>
    api.request<LabOrder>(`/api/v1/lab-orders/${orderId}/process`, { method: 'POST', body: {}, ...opt(facilityId) }),

  enterResults: (orderId: string, payload: { items: Array<{ labOrderId: string; resultValue: string; resultUnit?: string; referenceRange?: string }> }, facilityId?: string | null) =>
    api.request<LabOrder>(`/api/v1/lab-orders/${orderId}/results`, { method: 'POST', body: payload, ...opt(facilityId) }),

  verify: (orderId: string, facilityId?: string | null) =>
    api.request<LabOrder>(`/api/v1/lab-orders/${orderId}/verify`, { method: 'POST', body: {}, ...opt(facilityId) }),

  report: (orderId: string, facilityId?: string | null) =>
    api.request<LabOrder>(`/api/v1/lab-orders/${orderId}/report`, { method: 'POST', body: {}, ...opt(facilityId) }),
};

export const criticalValueApi = {
  list: (facilityId?: string | null) =>
    api.request<CriticalValueEvent[]>('/api/v1/critical-value-events', opt(facilityId)),

  acknowledge: (eventId: string, facilityId?: string | null) =>
    api.request<CriticalValueEvent>(`/api/v1/critical-value-events/${eventId}/acknowledge`, { method: 'POST', body: {}, ...opt(facilityId) }),

  escalate: (eventId: string, payload: { reason?: string }, facilityId?: string | null) =>
    api.request<CriticalValueEvent>(`/api/v1/critical-value-events/${eventId}/escalate`, { method: 'POST', body: payload, ...opt(facilityId) }),
};

export const radiologyApi = {
  storeOrder: (encounterId: string, payload: { testIds: string[]; priority?: string; clinicalIndication?: string }, facilityId?: string | null) =>
    api.request<RadiologyOrder>(`/api/v1/encounters/${encounterId}/radiology-orders`, { method: 'POST', body: payload, ...opt(facilityId) }),

  queue: (facilityId?: string | null) =>
    api.request<RadiologyStudy[]>(`/api/v1/radiology/queue`, opt(facilityId)),

  modalities: (facilityId?: string | null) =>
    api.request<Modality[]>(`/api/v1/radiology/modalities`, opt(facilityId)),

  showStudy: (studyId: string, facilityId?: string | null) =>
    api.request<RadiologyStudy>(`/api/v1/studies/${studyId}`, opt(facilityId)),

  schedule: (studyId: string, payload: { modalityId: string; scheduledAt: string }, facilityId?: string | null) =>
    api.request<RadiologyStudy>(`/api/v1/studies/${studyId}/schedule`, { method: 'POST', body: payload, ...opt(facilityId) }),

  perform: (studyId: string, payload: { findings?: string }, facilityId?: string | null) =>
    api.request<RadiologyStudy>(`/api/v1/studies/${studyId}/perform`, { method: 'POST', body: payload, ...opt(facilityId) }),

  draftReport: (studyId: string, payload: { content: string; reportType?: string }, facilityId?: string | null) =>
    api.request<RadiologyStudy>(`/api/v1/studies/${studyId}/report`, { method: 'POST', body: payload, ...opt(facilityId) }),

  imagingHistory: (patientId: string, facilityId?: string | null) =>
    api.request(`/api/v1/patients/${patientId}/imaging-history`, opt(facilityId)),

  stats: (facilityId?: string | null) =>
    api.request('/api/v1/radiology/stats', opt(facilityId)),
};
