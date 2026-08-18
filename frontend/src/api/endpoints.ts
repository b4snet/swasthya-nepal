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
  CriticalValueEvent,
  Diagnosis,
  Encounter,
  FollowUp,
  FollowUpReminder,
  Invoice,
  LabOrder,
  LabTest,
  LoginResponse,
  Medication,
  Modality,
  Patient,
  PatientContact,
  PatientIdentifier,
  PatientListItem,
  Prescription,
  QueueEntry,
  RadiologyOrder,
  RadiologyStudy,
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

export const followUpsApi = {
  forEncounter: (encounterId: string, facilityId?: string | null) =>
    api.request<FollowUp[]>(`/api/v1/encounters/${encounterId}/follow-ups`, opt(facilityId)),

  forPatient: (patientId: string, facilityId?: string | null) =>
    api.request<FollowUp[]>(`/api/v1/patients/${patientId}/follow-ups`, opt(facilityId)),

  create: (
    encounterId: string,
    payload: { followUpType: string; plannedAt: string; reason?: string; providerStaffId?: string },
    facilityId?: string | null,
  ) => api.request<FollowUp>(`/api/v1/encounters/${encounterId}/follow-ups`, { method: 'POST', body: payload, ...opt(facilityId) }),

  book: (followUpId: string, appointmentId: string, facilityId?: string | null) =>
    api.request<FollowUp>(`/api/v1/follow-ups/${followUpId}/book`, { method: 'POST', body: { appointmentId }, ...opt(facilityId) }),

  autoBook: (followUpId: string, facilityId?: string | null) =>
    api.request<{ followUp: FollowUp; appointment: Appointment }>(`/api/v1/follow-ups/${followUpId}/auto-book`, { method: 'POST', body: {}, ...opt(facilityId) }),

  cancel: (followUpId: string, reason: string, facilityId?: string | null) =>
    api.request<FollowUp>(`/api/v1/follow-ups/${followUpId}/cancel`, { method: 'POST', body: { reason }, ...opt(facilityId) }),

  complete: (followUpId: string, facilityId?: string | null) =>
    api.request<FollowUp>(`/api/v1/follow-ups/${followUpId}/complete`, { method: 'POST', body: {}, ...opt(facilityId) }),

  remind: (followUpId: string, facilityId?: string | null) =>
    api.request<FollowUpReminder>(`/api/v1/follow-ups/${followUpId}/remind`, { method: 'POST', body: {}, ...opt(facilityId) }),

  reminder: (followUpId: string, facilityId?: string | null) =>
    api.request<FollowUpReminder>(`/api/v1/follow-ups/${followUpId}/reminder`, opt(facilityId)),
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

/* ------------------------------------------------------------------
   Admin module endpoints
   ------------------------------------------------------------------ */

import type {
  AdminUser,
  AdminStaff,
  AdminService,
  Department,
  Facility,
  FacilitySetting,
  Organization,
  Permission,
  Role,
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
  create: (orgId: string, payload: { name: string; code: string; facilityId?: string; branchId?: string; parentDepartmentId?: string }) =>
    api.request<Department>(`/api/v1/organizations/${orgId}/departments`, { method: 'POST', body: payload }),
  update: (id: string, payload: Partial<{ name: string; code: string; status: string }>) =>
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

/* ------------------------------------------------------------------
   Laboratory (PRODUCT_REQUIREMENTS §6.8)
   ------------------------------------------------------------------ */

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

/* ------------------------------------------------------------------
   Radiology (PRODUCT_REQUIREMENTS §6.9)
   ------------------------------------------------------------------ */

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
};

export type { Assignment };
