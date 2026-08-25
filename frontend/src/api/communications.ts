import { api, ApiError, type RequestOptions } from './client';
import type {

} from './types';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

function orgUrl(organizationId: string | null | undefined): string {
  if (!organizationId) {
    throw new ApiError('NO_TENANT_CONTEXT', 'Organization context is required for this request.');
  }
  return `/api/v1/organizations/${organizationId}`;
}

export const communicationApi = {
  list: (organizationId: string, params?: Record<string, string>) => {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.request<Array<Record<string, unknown>>>(`${orgUrl(organizationId)}/communication-templates${qs}`);
  },
  show: (templateId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/communication-templates/${templateId}`),
  create: (organizationId: string, payload: Record<string, unknown>) =>
    api.request<Record<string, unknown>>(`${orgUrl(organizationId)}/communication-templates`, { method: 'POST', body: payload }),
  update: (templateId: string, payload: Record<string, unknown>) =>
    api.request<Record<string, unknown>>(`/api/v1/communication-templates/${templateId}`, { method: 'PUT', body: payload }),
  delete: (templateId: string) =>
    api.request<void>(`/api/v1/communication-templates/${templateId}`, { method: 'DELETE' }),
  preview: (templateId: string, variables?: Record<string, string>) =>
    api.request<{ subject: string; body: string; sms: string | null; whatsapp: string | null }>(
      `/api/v1/communication-templates/${templateId}/preview`,
      { method: 'POST', body: { variables: variables ?? {} } },
    ),
  send: (templateId: string, payload: { variables: Record<string, string>; patientId?: string; channel?: string }) =>
    api.request<{ sent: string[]; failed: string[] }>(
      `/api/v1/communication-templates/${templateId}/send`,
      { method: 'POST', body: payload },
    ),
  categories: () =>
    api.request<{ categories: Record<string, string>; types: Record<string, string> }>('/api/v1/communication-templates/categories'),
  variablePresets: () =>
    api.request<Record<string, Array<Record<string, unknown>>>>('/api/v1/communication-templates/variable-presets'),
};

export const telehealthApi = {
  list: () =>
    api.request<Array<Record<string, unknown>>>('/api/v1/telehealth/teleconsults'),
  show: (id: string) =>
    api.request<Record<string, unknown>>(`/api/v1/telehealth/teleconsults/${id}`),
  schedule: (appointmentId: string) =>
    api.request<Record<string, unknown>>('/api/v1/telehealth/schedule', { method: 'POST', body: { appointmentId } }),
  markReady: (id: string) =>
    api.request<Record<string, unknown>>(`/api/v1/telehealth/teleconsults/${id}/ready`, { method: 'POST', body: {} }),
  start: (id: string, medium: string = 'video') =>
    api.request<Record<string, unknown>>(`/api/v1/telehealth/teleconsults/${id}/start`, { method: 'POST', body: { medium } }),
  openVideoSession: (id: string, participantType: string = 'provider', recordingRequested: boolean = false) =>
    api.request<Record<string, unknown>>(`/api/v1/telehealth/teleconsults/${id}/video-sessions`, { method: 'POST', body: { participantType, recordingRequested } }),
  endVideoSession: (sessionId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/telehealth/video-sessions/${sessionId}/end`, { method: 'POST', body: {} }),
  failVideoSession: (sessionId: string, fallbackMode: string, fallbackReason: string) =>
    api.request<Record<string, unknown>>(`/api/v1/telehealth/video-sessions/${sessionId}/fail`, { method: 'POST', body: { fallbackMode, fallbackReason } }),
  complete: (id: string) =>
    api.request<Record<string, unknown>>(`/api/v1/telehealth/teleconsults/${id}/complete`, { method: 'POST', body: {} }),
  cancel: (id: string) =>
    api.request<Record<string, unknown>>(`/api/v1/telehealth/teleconsults/${id}/cancel`, { method: 'POST', body: {} }),
  waitingRoom: () =>
    api.request<Array<Record<string, unknown>>>('/api/v1/telehealth/waiting-room'),
  myConsults: () =>
    api.request<Array<Record<string, unknown>>>('/api/v1/telehealth/my-consults'),
};

export const notificationsApi = {
  templates: (facilityId?: string | null) =>
    api.request('/api/v1/notifications/templates', opt(facilityId)),

  storeTemplate: (payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request('/api/v1/notifications/templates', { method: 'POST', body: payload, ...opt(facilityId) }),

  segments: (facilityId?: string | null) =>
    api.request('/api/v1/notifications/segments', opt(facilityId)),

  storeSegment: (payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request('/api/v1/notifications/segments', { method: 'POST', body: payload, ...opt(facilityId) }),

  campaigns: (params?: { status?: string; emergency?: string }, facilityId?: string | null) => {
    const qs = params ? '?' + new URLSearchParams(params as Record<string, string>).toString() : '';
    return api.request(`/api/v1/notifications/campaigns${qs}`, opt(facilityId));
  },

  showCampaign: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/notifications/campaigns/${id}`, opt(facilityId)),

  storeCampaign: (payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request('/api/v1/notifications/campaigns', { method: 'POST', body: payload, ...opt(facilityId) }),

  transitionCampaign: (id: string, action: string, facilityId?: string | null) =>
    api.request(`/api/v1/notifications/campaigns/${id}/${action}`, { method: 'POST', ...opt(facilityId) }),

  campaignDelivery: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/notifications/campaigns/${id}/delivery`, opt(facilityId)),

  acknowledgeDelivery: (attemptId: string, facilityId?: string | null) =>
    api.request(`/api/v1/notifications/deliveries/${attemptId}/acknowledge`, { method: 'POST', ...opt(facilityId) }),

  emergencyBroadcast: (payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request('/api/v1/notifications/emergency', { method: 'POST', body: payload, ...opt(facilityId) }),

  stats: (facilityId?: string | null) =>
    api.request('/api/v1/notifications/stats', opt(facilityId)),
};
