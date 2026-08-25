import { api, ApiError, type RequestOptions } from './client';
import { portalRequest } from './portalClient';
import type {
  PatientSearchResult,
Patient, PatientContact, PatientIdentifier, PatientListItem, TimelineEntry,
} from './types';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

function orgUrl(organizationId: string | null | undefined): string {
  if (!organizationId) {
    throw new ApiError('NO_TENANT_CONTEXT', 'Organization context is required for this request.');
  }
  return `/api/v1/organizations/${organizationId}`;
}

export const patientsApi = {
  list: (organizationId: string, params: { search?: string; page?: number; facilityId?: string | null }) => {
    const qs = new URLSearchParams();
    if (params.search) qs.set('search', params.search);
    if (params.page) qs.set('page', String(params.page));
    return api.request<PatientListItem[]>(`${orgUrl(organizationId)}/patients?${qs}`, opt(params.facilityId));
  },

  search: (q: string, facilityId?: string | null) =>
    api.request<PatientSearchResult[]>(`/api/v1/patients/search?q=${encodeURIComponent(q)}`, opt(facilityId)),

  show: (id: string, facilityId?: string | null) => api.request<Patient>(`/api/v1/patients/${id}`, opt(facilityId)),

  create: (
    organizationId: string,
    payload: {
      fullName: string;
      dateOfBirth: string;
      sex: string;
      bloodGroup?: string;
      facilityId: string;
      phone?: string;
      email?: string;
      address?: Record<string, unknown>;
      emergencyContact?: { name: string; relation: string; phone: string };
      identifiers?: Array<{ type: string; value: string; issuingCountry?: string }>;
    },
  ) => api.request<Patient>(`${orgUrl(organizationId)}/patients`, { method: 'POST', body: payload, facilityId: payload.facilityId }),

  timeline: (id: string, facilityId?: string | null) => api.request<TimelineEntry[]>(`/api/v1/patients/${id}/timeline`, opt(facilityId)),

  update: (id: string, payload: Partial<{ fullName: string; dateOfBirth: string; sex: string; bloodGroup: string }>, facilityId?: string | null) =>
    api.request<Patient>(`/api/v1/patients/${id}`, { method: 'PATCH', body: payload, ...opt(facilityId) }),

  identifiers: (id: string, facilityId?: string | null) =>
    api.request<PatientIdentifier[]>(`/api/v1/patients/${id}/identifiers`, opt(facilityId)),

  addIdentifier: (id: string, payload: { type: string; value: string; issuingCountry?: string }, facilityId?: string | null) =>
    api.request<PatientIdentifier>(`/api/v1/patients/${id}/identifiers`, { method: 'POST', body: payload, ...opt(facilityId) }),

  contacts: (id: string, facilityId?: string | null) =>
    api.request<PatientContact[]>(`/api/v1/patients/${id}/contacts`, opt(facilityId)),

  addContact: (id: string, payload: { type: string; value: string; isPrimary?: boolean }, facilityId?: string | null) =>
    api.request<PatientContact>(`/api/v1/patients/${id}/contacts`, { method: 'POST', body: payload, ...opt(facilityId) }),

  updateContact: (patientId: string, contactId: string, payload: Partial<{ value: string; isPrimary: boolean; status: string }>, facilityId?: string | null) =>
    api.request<PatientContact>(`/api/v1/patients/${patientId}/contacts/${contactId}`, { method: 'PATCH', body: payload, ...opt(facilityId) }),

  // Patient CSV import (Phase 80)
  importTemplate: (organizationId: string) =>
    api.request<{ csv: string; columns: Record<string, string>; fileName: string }>(
      `${orgUrl(organizationId)}/patients/import/template`,
    ),
  importUpload: (organizationId: string, file: File, facilityId?: string | null) => {
    const fd = new FormData();
    fd.append('file', file);
    if (facilityId) fd.append('facilityId', facilityId);
    return api.request<{ importId: string; headers: string[]; totalRows: number }>(
      `${orgUrl(organizationId)}/patients/import`,
      { method: 'POST', body: fd as unknown as Record<string, unknown> },
    );
  },
  importShow: (importId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/patient-imports/${importId}`),
  importMapping: (importId: string, fieldMapping: Record<string, string>) =>
    api.request<{ mapping: Record<string, string> }>(`/api/v1/patient-imports/${importId}/mapping`, { method: 'PUT', body: { fieldMapping } }),
  importPreview: (importId: string) =>
    api.request<{ totalRows: number; validRows: number; errorRows: number; preview: Array<Record<string, unknown>>; errorSummary: Array<Record<string, unknown>> }>(
      `/api/v1/patient-imports/${importId}/preview`, { method: 'POST', body: {} },
    ),
  importExecute: (importId: string) =>
    api.request<{ success: number; errors: number; errorDetails: Array<Record<string, unknown>> }>(
      `/api/v1/patient-imports/${importId}/import`, { method: 'POST', body: {} },
    ),
  importList: (organizationId: string) =>
    api.request<Array<Record<string, unknown>>>(`${orgUrl(organizationId)}/patient-imports`),

  // Portal invitation (Phase 82)
  sendPortalInvite: (patientId: string, payload: { email?: string; phone?: string }) =>
    api.request<{ invitationId: string; token: string; expiresAt: string }>(
      `/api/v1/patients/${patientId}/portal/invite`,
      { method: 'POST', body: payload },
    ),

  // ── Patient longitudinal record sub-resources ──
  diagnoses: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/diagnoses`, opt(facilityId)),

  prescriptions: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/prescriptions`, opt(facilityId)),

  allergies: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/allergies`, opt(facilityId)),

  medications: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/medications`, opt(facilityId)),

  admissions: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/admissions`, opt(facilityId)),

  documents: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/documents`, opt(facilityId)),

  labOrders: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/lab-orders`, opt(facilityId)),

  radiologyOrders: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/radiology-orders`, opt(facilityId)),

  referrals: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/referrals`, opt(facilityId)),

  followUps: (patientId: string, facilityId?: string | null) =>
    api.request<any[]>(`/api/v1/patients/${patientId}/follow-ups`, opt(facilityId)),
};

export const portalApi = {
  login: (orgCode: string, identifier: string, password: string) =>
    portalRequest<{ token: string; tokenType: string; expiresAt: string; account: unknown }>('/api/v1/portal/login', {
      method: 'POST',
      body: { organizationCode: orgCode, identifier, password },
    }),

  me: () => portalRequest('/api/v1/portal/me'),

  profile: () => portalRequest('/api/v1/portal/profile'),

  medicalHistory: () => portalRequest('/api/v1/portal/medical-history'),

  medications: () => portalRequest('/api/v1/portal/medications'),

  labResults: () => portalRequest('/api/v1/portal/lab-results'),

  radiologyReports: () => portalRequest('/api/v1/portal/radiology-reports'),

  prescriptions: () => portalRequest('/api/v1/portal/prescriptions'),

  documents: () => portalRequest('/api/v1/portal/documents'),

  /** View a shared document's HTML content */
  showDocument: (documentId: string) =>
    portalRequest<{ id: string; documentNumber: string; documentType: string; category: string; title: string; providerName: string; departmentName: string; status: string; contentHtml: string; hasPdf: boolean; createdAt: string }>(`/api/v1/portal/documents/${documentId}`),

  /** Get the PDF download URL for a shared document */
  documentPdfUrl: (documentId: string) => `/api/v1/portal/documents/${documentId}/pdf`,

  referrals: () => portalRequest('/api/v1/portal/referrals'),

  immunizations: () => portalRequest('/api/v1/portal/immunizations'),

  appointments: () => portalRequest('/api/v1/portal/appointments'),

  bills: () => portalRequest('/api/v1/portal/bills'),

  grants: () => portalRequest('/api/v1/portal/grants'),

  revokeGrant: (grantId: string) =>
    portalRequest(`/api/v1/portal/grants/${grantId}/revoke`, { method: 'POST', body: {} }),

  messages: () => portalRequest('/api/v1/portal/messages'),

  sendMessage: (payload: { recipientStaffId: string; subject: string; body: string; category?: string }) =>
    portalRequest('/api/v1/portal/messages', { method: 'POST', body: payload }),

  notificationPreferences: () => portalRequest('/api/v1/portal/notification-preferences'),

  updateNotificationPreferences: (payload: Record<string, unknown>) =>
    portalRequest('/api/v1/portal/notification-preferences', { method: 'PUT', body: payload }),

  consentRecords: () => portalRequest('/api/v1/portal/consents'),

  revokeConsent: (consentId: string, reason?: string) =>
    api.request('/api/v1/portal/consents/revoke', { method: 'POST', body: { consentId, reason } }),
};
