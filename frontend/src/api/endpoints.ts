import { api, ApiError, type RequestOptions } from './client';

/**
 * Build an organization-scoped API path. The SPA never issues tenant-less
 * requests: an empty organization id (context not yet resolved, or a
 * platform-only principal) fails fast client-side instead of producing a
 * malformed URL that the backend must reject.
 */
function orgUrl(organizationId: string | null | undefined): string {
  if (!organizationId) {
    throw new ApiError('NO_TENANT_CONTEXT', 'Organization context is required for this request.');
  }
  return `/api/v1/organizations/${organizationId}`;
}
import type {
  Appointment,
  Assignment,
  AuditEvent,
  AvailabilitySlot,
  ClinicalNote,
  Diagnosis,
  Encounter,
  Invoice,
  LoginResponse,
  Medication,
  Patient,
  PatientListItem,
  Prescription,
  QueueEntry,
  Service,
  Staff,
  TimelineEntry,
} from './types';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

export const authApi = {
  login: (email: string, password: string) =>
    api.request<LoginResponse>('/api/v1/auth/login', { method: 'POST', body: { email, password } }),

  refresh: (refreshToken: string) =>
    api.request<LoginResponse>('/api/v1/auth/refresh', { method: 'POST', body: { refreshToken } }),

  logout: (facilityId?: string | null) => api.request<void>('/api/v1/auth/logout', { method: 'POST', ...opt(facilityId) }),
};

export interface PatientSearchResult {
  id: string;
  mrn: string;
  fullName: string;
  dateOfBirth: string;
  sex: string;
  status: string;
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
};

export const appointmentsApi = {
  list: (params: { date?: string; facilityId?: string | null }) => {
    const qs = new URLSearchParams();
    if (params.date) qs.set('date', params.date);
    return api.request<Appointment[]>(`/api/v1/appointments?${qs}`, opt(params.facilityId));
  },

  queue: (params: { date?: string; providerStaffId?: string; facilityId?: string | null }) => {
    const qs = new URLSearchParams();
    if (params.date) qs.set('date', params.date);
    if (params.providerStaffId) qs.set('providerStaffId', params.providerStaffId);
    return api.request<QueueEntry[]>(`/api/v1/appointments/queue?${qs}`, opt(params.facilityId));
  },

  show: (id: string, facilityId?: string | null) => api.request<Appointment>(`/api/v1/appointments/${id}`, opt(facilityId)),

  book: (payload: {
    patientId: string;
    providerStaffId: string;
    serviceId?: string;
    startsAt: string;
    endsAt: string;
    appointmentType?: string;
    source?: string;
    facilityId: string;
  }) => {
    // facilityId is a header-only tenant proposal (X-Swasthya-Facility): the
    // backend BookAppointmentRequest forbids it in the body. Sending it there
    // yields 422 "field is not allowed".
    const { facilityId, ...body } = payload;
    return api.request<Appointment>('/api/v1/appointments', { method: 'POST', body, facilityId });
  },

  checkIn: (id: string, facilityId?: string | null) =>
    api.request<Appointment>(`/api/v1/appointments/${id}/check-in`, { method: 'POST', body: {}, ...opt(facilityId) }),

  cancel: (id: string, reason: string, facilityId?: string | null) =>
    api.request<Appointment>(`/api/v1/appointments/${id}/cancel`, { method: 'POST', body: { reason }, ...opt(facilityId) }),
};

export const scheduleApi = {
  availability: (staffId: string, date: string, facilityId?: string | null) =>
    api.request<AvailabilitySlot[]>(`/api/v1/staff/${staffId}/availability?date=${date}`, opt(facilityId)),
};

export const encountersApi = {
  start: (appointmentId: string, facilityId?: string | null) =>
    api.request<Encounter>(`/api/v1/appointments/${appointmentId}/start-encounter`, { method: 'POST', body: {}, ...opt(facilityId) }),

  show: (id: string, facilityId?: string | null) => api.request<Encounter>(`/api/v1/encounters/${id}`, opt(facilityId)),

  notes: (id: string, facilityId?: string | null) => api.request<ClinicalNote[]>(`/api/v1/encounters/${id}/notes`, opt(facilityId)),

  storeNote: (id: string, noteType: string, content: Record<string, string>, facilityId?: string | null) =>
    api.request<ClinicalNote>(`/api/v1/encounters/${id}/notes`, { method: 'POST', body: { noteType, content }, ...opt(facilityId) }),

  signNote: (encounterId: string, noteId: string, facilityId?: string | null) =>
    api.request<ClinicalNote>(`/api/v1/encounters/${encounterId}/notes/${noteId}/sign`, { method: 'POST', body: {}, ...opt(facilityId) }),

  storeDiagnosis: (
    id: string,
    payload: { code?: string; codingSystem?: string; description: string; diagnosisType?: string; isPrimary?: boolean; onsetDate?: string },
    facilityId?: string | null,
  ) => api.request<Diagnosis>(`/api/v1/encounters/${id}/diagnoses`, { method: 'POST', body: payload, ...opt(facilityId) }),

  storePrescription: (
    id: string,
    payload: { notes?: string; lines: Array<{ medicationId: string; dose: string; route: string; frequency: string; duration?: string; quantityMinor?: number; instructions?: string }> },
    facilityId?: string | null,
  ) => api.request<Prescription>(`/api/v1/encounters/${id}/prescriptions`, { method: 'POST', body: payload, ...opt(facilityId) }),

  sign: (id: string, facilityId?: string | null) =>
    api.request<Encounter>(`/api/v1/encounters/${id}/sign`, { method: 'POST', body: {}, ...opt(facilityId) }),
};

export const billingApi = {
  invoice: (encounterId: string, facilityId?: string | null) =>
    api.request<Invoice>(`/api/v1/encounters/${encounterId}/invoice`, { method: 'POST', body: {}, ...opt(facilityId) }),

  invoiceShow: (id: string, facilityId?: string | null) => api.request<Invoice>(`/api/v1/invoices/${id}`, opt(facilityId)),

  pay: (invoiceId: string, payload: { method: string; amountMinor: number; idempotencyKey: string; providerRef?: string }, facilityId?: string | null) =>
    api.request<{ paymentId: string; status: string; amountMinor: number; method: string; invoice: Invoice }>(
      `/api/v1/invoices/${invoiceId}/pay`,
      { method: 'POST', body: payload, ...opt(facilityId) },
    ),
};

export const auditApi = {
  list: (params: { limit?: number; facilityId?: string | null }) => {
    const qs = new URLSearchParams();
    if (params.limit) qs.set('limit', String(params.limit));
    return api.request<AuditEvent[]>(`/api/v1/audit-events?${qs}`, opt(params.facilityId));
  },
};

export const catalogsApi = {
  staff: (organizationId: string, facilityId?: string | null) =>
    api.request<Staff[]>(`${orgUrl(organizationId)}/staff`, opt(facilityId)),

  services: (organizationId: string, facilityId?: string | null) =>
    api.request<Service[]>(`${orgUrl(organizationId)}/services`, opt(facilityId)),

  medications: (organizationId: string, facilityId?: string | null) =>
    api.request<Medication[]>(`${orgUrl(organizationId)}/medications`, opt(facilityId)),
};

export type { Assignment };
