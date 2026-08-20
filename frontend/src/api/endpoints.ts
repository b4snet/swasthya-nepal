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
  Dashboard,
  Diagnosis,
  Encounter,
  FollowUp,
  FollowUpReminder,
  InventoryAdjustmentRequest,
  InventoryItem,
  Invoice,
  KpiDefinition,
  KpiMetric,
  LabOrder,
  LabTest,
  LoginResponse,
  Medication,
  Modality,
  Patient,
  PatientContact,
  PatientIdentifier,
  PatientListItem,
  PharmacyPrescription,
  PurchaseOrder,
  PurchaseRequest,
  QueueEntry,
  RadiologyOrder,
  RadiologyStudy,
  ReportRun,
  ReportTemplate,
  Service,
  Settlement,
  Staff,
  StockBatch,
  TimelineEntry,
  Vendor,
  Deposit,
  AgingEntry,
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
  ) => api.request<PharmacyPrescription>(`/api/v1/encounters/${id}/prescriptions`, { method: 'POST', body: payload, ...opt(facilityId) }),

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

export const icuApi = {
  beds: () => api.request<Array<{
    id: string; bedCode: string; status: string; acuitySupported: string;
  }>>('/api/v1/icu-beds'),
  createBed: (payload: { bedCode: string; acuitySupported?: string }) =>
    api.request<{ id: string; bedCode: string; status: string }>(
      '/api/v1/icu-beds', { method: 'POST', body: payload },
    ),
  admit: (payload: {
    patientId: string; icuBedId: string; source?: string; acuity?: string;
    observationIntervalMinutes?: number; admissionId?: string; handoverNotes?: string;
  }) => api.request<{ id: string; patientId: string; acuity: string; status: string }>(
    '/api/v1/icu-admissions', { method: 'POST', body: payload },
  ),
  show: (admissionId: string) => api.request<{
    id: string; patientId: string; acuity: string; status: string;
    nextObservationDueAt: string | null;
    recentScores: Array<{ id: string; total: number; severity: string; computedAt: string }>;
    openAlerts: Array<{ id: string; alertType: string; severity: string; message: string; status: string }>;
  }>(`/api/v1/icu-admissions/${admissionId}`),
  recordObservation: (admissionId: string, payload: {
    values: Record<string, number>; notes?: string; observedAt?: string;
  }) => api.request<{ observationSetId: string; score: { total: number; severity: string }; alerts: Array<{ id: string; severity: string; message: string }> }>(
    `/api/v1/icu-admissions/${admissionId}/observations`, { method: 'POST', body: payload },
  ),
  acknowledgeAlert: (alertId: string) => api.request<{ id: string; status: string; acknowledgedAt: string }>(
    `/api/v1/icu-alerts/${alertId}/acknowledge`, { method: 'POST' },
  ),
  documentCare: (admissionId: string, payload: { noteType: string; content: string; authoredAt?: string }) =>
    api.request<{ id: string; noteType: string }>(
      `/api/v1/icu-admissions/${admissionId}/notes`, { method: 'POST', body: payload },
    ),
  transferOut: (admissionId: string, payload: { handoverNotes?: string }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/icu-admissions/${admissionId}/transfer`, { method: 'POST', body: payload },
    ),
};

export const erApi = {
  register: (payload: {
    facilityId: string;
    patientName?: string;
    sex?: string;
    dateOfBirth?: string;
    estimatedAge?: number;
    presentingComplaint?: string;
  }) => api.request<{ id: string; patientId: string; mrn: string; encounterId: string; registeredAt: string }>(
    '/api/v1/er/registrations', { method: 'POST', body: payload },
  ),
  queue: () => api.request<Array<{
    encounterId: string; patientId: string; facilityId: string;
    registeredAt: string | null; triageLevel: number | null;
    triageColor: string | null; presentingComplaint: string | null;
  }>>('/api/v1/er/queue'),
  triageScales: (orgId: string) => api.request<Array<{
    id: string; code: string; name: string; level: number; color: string;
    reassessmentMinutes: number; isDefault: boolean; status: string;
  }>>(`/api/v1/organizations/${orgId}/er/triage-scales`),
  assignTriage: (encounterId: string, payload: { scaleId: string; overrideReason?: string }) =>
    api.request<{ id: string; level: number; color: string }>(
      `/api/v1/er/encounters/${encounterId}/triage`, { method: 'POST', body: payload },
    ),
  events: (encounterId: string) => api.request<Array<{
    id: string; eventType: string; occurredAt: string; actorStaffId: string;
  }>>(`/api/v1/er/encounters/${encounterId}/events`),
  addEvent: (encounterId: string, payload: { eventType: string; notes?: string; occurredAt?: string }) =>
    api.request<{ id: string; eventType: string; occurredAt: string }>(
      `/api/v1/er/encounters/${encounterId}/events`, { method: 'POST', body: payload },
    ),
  disposition: (encounterId: string, payload: {
    disposition: string; notes?: string; bedId?: string; admittingDiagnosis?: string;
  }) => api.request<{ encounter: { id: string; disposition: string; status: string }; admissionId: string | null }>(    `/api/v1/er/encounters/${encounterId}/disposition`, { method: 'POST', body: payload },
  ),
};

/* ------------------------------------------------------------------
   Operating Theatre (Phase 50)
   ------------------------------------------------------------------ */

export const otApi = {
  theatres: () => api.request<Array<{
    id: string; code: string; name: string; status: string;
  }>>('/api/v1/theatres'),
  createTheatre: (payload: { code: string; name: string; status?: string }) =>
    api.request<{ id: string; code: string; name: string; status: string }>(
      '/api/v1/theatres', { method: 'POST', body: payload },
    ),
  procedureRequests: () => api.request<Array<{
    id: string; patientId: string; encounterId: string | null;
    procedureName: string; priority: string; status: string;
    theatreId: string | null; scheduledAt: string | null;
    durationMinutes: number | null; requestedBy: string;
  }>>('/api/v1/procedure-requests'),
  createProcedureRequest: (payload: {
    patientId: string; encounterId?: string; procedureName: string; priority?: string;
  }) => api.request<{ id: string; status: string }>(
    '/api/v1/procedure-requests', { method: 'POST', body: payload },
  ),
  schedule: (requestId: string, payload: {
    theatreId: string; scheduledAt: string; durationMinutes: number;
  }) => api.request<{ id: string; status: string; scheduledAt: string }>(
    `/api/v1/procedure-requests/${requestId}/schedule`, { method: 'POST', body: payload },
  ),
  cancel: (requestId: string) => api.request<{ id: string; status: string }>(
    `/api/v1/procedure-requests/${requestId}/cancel`, { method: 'POST' },
  ),
  start: (requestId: string, payload: {
    checklistTemplateId?: string; surgeonStaffId: string;
  }) => api.request<{ id: string; status: string; checklist: Array<{
    id: string; stepKey: string; label: string; completedAt: string | null;
  }> }>(
    `/api/v1/procedure-requests/${requestId}/start`, { method: 'POST', body: payload },
  ),
  showProcedure: (id: string) => api.request<{
    id: string; status: string; patientId: string; theatreId: string;
    team: Array<{ id: string; staffId: string; role: string; timeIn: string | null }>;
    events: Array<{ id: string; eventType: string; occurredAt: string }>;
    checklist: Array<{ id: string; stepKey: string; label: string; completedAt: string | null }>;
    recovery: { id: string; status: string } | null;
  }>(`/api/v1/procedures/${id}`),
  addTeamMember: (procedureId: string, payload: { staffId: string; role: string; timeIn?: string }) =>
    api.request<{ id: string; staffId: string; role: string }>(
      `/api/v1/procedures/${procedureId}/team`, { method: 'POST', body: payload },
    ),
  startAnesthesia: (procedureId: string, payload: {
    anesthetistStaffId: string; anesthesiaType: string; notes?: string;
  }) => api.request<{ id: string; anesthesiaType: string }>(
    `/api/v1/procedures/${procedureId}/anesthesia`, { method: 'POST', body: payload },
  ),
  recordEvent: (procedureId: string, payload: {
    eventType: string; staffId?: string; notes?: string;
  }) => api.request<{ id: string; eventType: string; occurredAt: string }>(
    `/api/v1/procedures/${procedureId}/events`, { method: 'POST', body: payload },
  ),
  completeChecklist: (procedureId: string, itemId: string) =>
    api.request<{ id: string; completedAt: string }>(
      `/api/v1/procedures/${procedureId}/checklist/${itemId}/complete`, { method: 'POST' },
    ),
  close: (procedureId: string) => api.request<{ id: string; status: string; endedAt: string }>(
    `/api/v1/procedures/${procedureId}/close`, { method: 'POST' },
  ),
  admitToRecovery: (procedureId: string, payload?: { observations?: Record<string, string> }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/procedures/${procedureId}/recovery`, { method: 'POST', body: payload ?? {} },
    ),
  dischargeRecovery: (recoveryId: string) => api.request<{ id: string; status: string }>(
    `/api/v1/recovery/${recoveryId}/discharge`, { method: 'POST' },
  ),
};

/* ------------------------------------------------------------------
   Blood Bank (Phase 51)
   ------------------------------------------------------------------ */

export const bbApi = {
  donors: () => api.request<Array<{
    id: string; donorNumber: string; bloodGroup: string; rhFactor: string; status: string;
  }>>('/api/v1/donors'),
  createDonor: (payload: {
    donorNumber: string; bloodGroup: string; rhFactor: string;
  }) => api.request<{ id: string; donorNumber: string; bloodGroup: string }>(
    '/api/v1/donors', { method: 'POST', body: payload },
  ),
  recordDonation: (donorId: string, payload: {
    phlebotomistStaffId: string; volumeMl?: number; components?: Array<{ componentType: string; expiryDays?: number }>;
  }) => api.request<{ donationId: string; units: Array<{ id: string; unitNumber: string; componentType: string; status: string }> }>(
    `/api/v1/donors/${donorId}/donations`, { method: 'POST', body: payload },
  ),
  testUnit: (unitId: string, payload: { testResults: Record<string, string>; suitable: boolean }) =>
    api.request<{ id: string; unitNumber: string; status: string }>(
      `/api/v1/blood-units/${unitId}/test`, { method: 'POST', body: payload },
    ),
  requestCrossmatch: (unitId: string, payload: { patientId: string }) =>
    api.request<{ id: string; bloodUnitId: string; patientId: string; status: string }>(
      `/api/v1/blood-units/${unitId}/crossmatch`, { method: 'POST', body: payload },
    ),
  performCrossmatch: (crossmatchId: string, payload: { compatible: boolean; notes?: string }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/crossmatches/${crossmatchId}/perform`, { method: 'POST', body: payload },
    ),
  issueUnit: (unitId: string, payload: { patientId: string; issuedToStaffId: string }) =>
    api.request<{ id: string; unitNumber: string; status: string }>(
      `/api/v1/blood-units/${unitId}/issue`, { method: 'POST', body: payload },
    ),
  startTransfusion: (payload: { bloodUnitId: string; patientId: string; prescribedByStaffId: string }) =>
    api.request<{ id: string; status: string }>(
      '/api/v1/transfusions', { method: 'POST', body: payload },
    ),
  verifyTransfusion: (id: string, payload: { verifiedByStaffId: string }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/transfusions/${id}/verify`, { method: 'POST', body: payload },
    ),
  completeTransfusion: (id: string, payload: { volumeTransfusedMl: number; completedByStaffId: string }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/transfusions/${id}/complete`, { method: 'POST', body: payload },
    ),
  stopTransfusion: (id: string, payload: { reason: string; stoppedByStaffId: string }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/transfusions/${id}/stop`, { method: 'POST', body: payload },
    ),
  reportReaction: (id: string, payload: { severity: string; description: string; reportedByStaffId: string }) =>
    api.request<{ id: string; severity: string; status: string }>(
      `/api/v1/transfusions/${id}/reaction`, { method: 'POST', body: payload },
    ),
  discardUnit: (unitId: string, payload: { reason: string }) =>
    api.request<{ id: string; unitNumber: string; status: string }>(
      `/api/v1/blood-units/${unitId}/discard`, { method: 'POST', body: payload },
    ),
};

/* ------------------------------------------------------------------
   Nursing Workflow (Phase 52)
   ------------------------------------------------------------------ */

export const nursingApi = {
  tasks: () => api.request<Array<{
    id: string; patientId: string; taskType: string; description: string;
    priority: string; status: string; dueAt: string | null;
  }>>('/api/v1/nursing/tasks'),
  createTask: (payload: {
    patientId: string; taskType: string; description: string;
    priority?: string; assignedTo?: string; dueAt?: string; admissionId?: string;
  }) => api.request<{ id: string; status: string }>(
    '/api/v1/nursing/tasks', { method: 'POST', body: payload },
  ),
  completeTask: (taskId: string, payload?: { completionNotes?: string; completedBy?: string }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/nursing/tasks/${taskId}/complete`, { method: 'POST', body: payload ?? {} },
    ),
  vitals: () => api.request<Array<{
    id: string; patientId: string; temperatureCelsius: number | null;
    heartRateBpm: number | null; systolicBp: number | null; diastolicBp: number | null;
    spo2Percent: number | null; painScore: number | null; observedAt: string;
  }>>('/api/v1/nursing/vitals'),
  recordVital: (payload: {
    patientId: string; recordedBy: string; observedAt: string;
    temperatureCelsius?: number; heartRateBpm?: number; respiratoryRate?: number;
    systolicBp?: number; diastolicBp?: number; spo2Percent?: number;
    painScore?: number; gcsScore?: number; notes?: string; admissionId?: string;
  }) => api.request<{ id: string }>(
    '/api/v1/nursing/vitals', { method: 'POST', body: payload },
  ),
  carePlans: () => api.request<Array<{
    id: string; patientId: string; diagnosis: string; goals: string;
    interventions: string; status: string;
  }>>('/api/v1/nursing/care-plans'),
  createCarePlan: (payload: {
    patientId: string; createdBy: string; diagnosis: string;
    goals: string; interventions: string; effectiveFrom: string;
    effectiveUntil?: string; admissionId?: string;
  }) => api.request<{ id: string; status: string }>(
    '/api/v1/nursing/care-plans', { method: 'POST', body: payload },
  ),
  handovers: () => api.request<Array<{
    id: string; shift: string; handoverDate: string; status: string;
  }>>('/api/v1/nursing/handovers'),
  createHandover: (payload: {
    outgoingStaffId: string; incomingStaffId: string; shift: string;
    handoverDate: string; patientSummaries: string;
    criticalItems?: string; pendingTasks?: string;
  }) => api.request<{ id: string; status: string }>(
    '/api/v1/nursing/handovers', { method: 'POST', body: payload },
  ),
  acceptHandover: (id: string, payload?: { acceptedBy?: string }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/nursing/handovers/${id}/accept`, { method: 'POST', body: payload ?? {} },
    ),
  alerts: () => api.request<Array<{
    id: string; patientId: string; alertType: string; severity: string;
    message: string; status: string;
  }>>('/api/v1/nursing/alerts'),
  createAlert: (payload: {
    patientId: string; alertTo: string; alertType: string;
    severity?: string; message: string;
  }) => api.request<{ id: string; status: string }>(
    '/api/v1/nursing/alerts', { method: 'POST', body: payload },
  ),
  acknowledgeAlert: (id: string, payload?: { acknowledgedBy?: string }) =>
    api.request<{ id: string; status: string }>(
      `/api/v1/nursing/alerts/${id}/acknowledge`, { method: 'POST', body: payload ?? {} },
    ),
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

  imagingHistory: (patientId: string, facilityId?: string | null) =>
    api.request(`/api/v1/patients/${patientId}/imaging-history`, opt(facilityId)),

  stats: (facilityId?: string | null) =>
    api.request('/api/v1/radiology/stats', opt(facilityId)),
};

/* ------------------------------------------------------------------
   Pharmacy (PRODUCT_REQUIREMENTS §6.7)
   ------------------------------------------------------------------ */

export const pharmacyApi = {
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

/* ------------------------------------------------------------------
   Inventory (PRODUCT_REQUIREMENTS §6.14)
   ------------------------------------------------------------------ */

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

/* ------------------------------------------------------------------
   Procurement (PRODUCT_REQUIREMENTS §6.15–§6.16)
   ------------------------------------------------------------------ */

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

/* ------------------------------------------------------------------
   Finance (PRODUCT_REQUIREMENTS §6.13–§6.14)
   ------------------------------------------------------------------ */

export const financeApi = {
  deposits: (patientId: string, facilityId?: string | null) =>
    api.request<Deposit[]>(`/api/v1/patients/${patientId}/deposits`, opt(facilityId)),

  collectDeposit: (patientId: string, payload: { amountMinor: number }, facilityId?: string | null) =>
    api.request<Deposit>(`/api/v1/patients/${patientId}/deposits`, { method: 'POST', body: payload, ...opt(facilityId) }),

  aging: (patientId: string, facilityId?: string | null) =>
    api.request<AgingEntry[]>(`/api/v1/patients/${patientId}/aging`, opt(facilityId)),

  settlements: (facilityId?: string | null) =>
    api.request<Settlement[]>('/api/v1/cashier-settlements', opt(facilityId)),

  reconcileSettlement: (payload: { settlementDate: string; actualMinor: number; notes?: string }, facilityId?: string | null) =>
    api.request<Settlement>('/api/v1/cashier-settlements/reconcile', { method: 'POST', body: payload, ...opt(facilityId) }),
};

/* ------------------------------------------------------------------
   Analytics & Reporting (ROADMAP Phase 17)
   ------------------------------------------------------------------ */

export const analyticsApi = {
  kpiDefinitions: (facilityId?: string | null) =>
    api.request<KpiDefinition[]>('/api/v1/analytics/kpi-definitions', opt(facilityId)),

  storeKpiDefinition: (payload: { code: string; name: string; domain: string; sourceTable: string; dateColumn: string; aggregation: string; filter?: string; sumColumn?: string; unit?: string }, facilityId?: string | null) =>
    api.request<KpiDefinition>('/api/v1/analytics/kpi-definitions', { method: 'POST', body: payload, ...opt(facilityId) }),

  showMetrics: (kpiId: string, facilityId?: string | null) =>
    api.request<KpiMetric[]>(`/api/v1/analytics/metrics/${kpiId}`, opt(facilityId)),

  dashboards: (facilityId?: string | null) =>
    api.request<Dashboard[]>(`/api/v1/analytics/dashboards`, opt(facilityId)),

  showDashboard: (id: string, facilityId?: string | null) =>
    api.request<Dashboard>(`/api/v1/analytics/dashboards/${id}`, opt(facilityId)),

  reportTemplates: (facilityId?: string | null) =>
    api.request<ReportTemplate[]>('/api/v1/analytics/report-templates', opt(facilityId)),

  storeReportTemplate: (payload: { code: string; name: string; category: string; scope: string; parameterSchema?: Record<string, unknown>; query?: string }, facilityId?: string | null) =>
    api.request<ReportTemplate>('/api/v1/analytics/report-templates', { method: 'POST', body: payload, ...opt(facilityId) }),

  reportRuns: (facilityId?: string | null) =>
    api.request<ReportRun[]>('/api/v1/analytics/report-runs', opt(facilityId)),

  runReport: (payload: { templateId: string; parameters?: Record<string, unknown> }, facilityId?: string | null) =>
    api.request<ReportRun>('/api/v1/analytics/reports/run', { method: 'POST', body: payload, ...opt(facilityId) }),

  exportReport: (payload: { templateId: string; format: string; parameters?: Record<string, unknown> }, facilityId?: string | null) =>
    api.request<ReportRun>('/api/v1/analytics/reports/export', { method: 'POST', body: payload, ...opt(facilityId) }),
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

/* ------------------------------------------------------------------
   Enterprise: Budgets, Expenses & Financial Periods (Phase 17)
   ------------------------------------------------------------------ */

export const enterpriseApi = {
  // Budgets
  budgets: (orgId: string, params?: { fiscal_year?: number; status?: string }, facilityId?: string | null) => {
    const qs = params ? '?' + new URLSearchParams(params as Record<string, string>).toString() : '';
    return api.request(`/api/v1/enterprise/organizations/${orgId}/budgets${qs}`, opt(facilityId));
  },
  storeBudget: (orgId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/budgets`, { method: 'POST', body: payload, ...opt(facilityId) }),
  showBudget: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/budgets/${id}`, opt(facilityId)),
  approveBudget: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/budgets/${id}/approve`, { method: 'POST', ...opt(facilityId) }),
  closeBudget: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/budgets/${id}/close`, { method: 'POST', ...opt(facilityId) }),
  storeBudgetLine: (id: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/budgets/${id}/lines`, { method: 'POST', body: payload, ...opt(facilityId) }),

  // Expense Categories
  expenseCategories: (orgId: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/expense-categories`, opt(facilityId)),
  storeExpenseCategory: (orgId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/expense-categories`, { method: 'POST', body: payload, ...opt(facilityId) }),

  // Expenses
  expenses: (orgId: string, params?: { status?: string; category_id?: string; budget_id?: string }, facilityId?: string | null) => {
    const qs = params ? '?' + new URLSearchParams(params as Record<string, string>).toString() : '';
    return api.request(`/api/v1/enterprise/organizations/${orgId}/expenses${qs}`, opt(facilityId));
  },
  storeExpense: (orgId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/expenses`, { method: 'POST', body: payload, ...opt(facilityId) }),
  showExpense: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}`, opt(facilityId)),
  submitExpense: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/submit`, { method: 'POST', ...opt(facilityId) }),
  approveExpense: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/approve`, { method: 'POST', ...opt(facilityId) }),
  rejectExpense: (id: string, reason: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/reject`, { method: 'POST', body: { reason }, ...opt(facilityId) }),
  payExpense: (id: string, payload: { paymentMethod: string; paymentReference?: string }, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/pay`, { method: 'POST', body: payload, ...opt(facilityId) }),
  voidExpense: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/void`, { method: 'POST', ...opt(facilityId) }),

  // Financial Periods
  financialPeriods: (orgId: string, params?: { fiscal_year?: number; status?: string }, facilityId?: string | null) => {
    const qs = params ? '?' + new URLSearchParams(params as Record<string, string>).toString() : '';
    return api.request(`/api/v1/enterprise/organizations/${orgId}/financial-periods${qs}`, opt(facilityId));
  },
  storeFinancialPeriod: (orgId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/financial-periods`, { method: 'POST', body: payload, ...opt(facilityId) }),
  showFinancialPeriod: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/financial-periods/${id}`, opt(facilityId)),
  closeFinancialPeriod: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/financial-periods/${id}/close`, { method: 'POST', ...opt(facilityId) }),
  lockFinancialPeriod: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/financial-periods/${id}/lock`, { method: 'POST', ...opt(facilityId) }),
};

export type { Deposit, AgingEntry, Settlement };
export type { Assignment };

/* ------------------------------------------------------------------
   Oncology & Radiotherapy (Phase 15)
   ------------------------------------------------------------------ */

export const oncologyApi = {
  listProfiles: (facilityId?: string | null) =>
    api.request<{ data: unknown[] }>(`/api/v1/oncology/profiles`, opt(facilityId)),

  storeProfile: (payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/profiles`, { method: 'POST', body: payload, ...opt(facilityId) }),

  showProfile: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/profiles/${id}`, opt(facilityId)),

  storeDiagnosis: (profileId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/profiles/${profileId}/diagnoses`, { method: 'POST', body: payload, ...opt(facilityId) }),

  storeTreatmentPlan: (profileId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/profiles/${profileId}/treatment-plans`, { method: 'POST', body: payload, ...opt(facilityId) }),

  startCycle: (planId: string, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/treatment-plans/${planId}/start`, { method: 'POST', ...opt(facilityId) }),

  completeCycle: (cycleId: string, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/cycles/${cycleId}/complete`, { method: 'POST', ...opt(facilityId) }),

  storeToxicity: (cycleId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/cycles/${cycleId}/toxicity`, { method: 'POST', body: payload, ...opt(facilityId) }),

  storeRtCourse: (profileId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/profiles/${profileId}/rt-courses`, { method: 'POST', body: payload, ...opt(facilityId) }),

  showRtCourse: (courseId: string, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-courses/${courseId}`, opt(facilityId)),

  storeRtPlan: (courseId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-courses/${courseId}/plans`, { method: 'POST', body: payload, ...opt(facilityId) }),

  submitRtPlan: (planId: string, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-plans/${planId}/submit`, { method: 'POST', ...opt(facilityId) }),

  physicistCheck: (planId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-plans/${planId}/physicist-check`, { method: 'POST', body: payload, ...opt(facilityId) }),

  secondaryCheck: (planId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-plans/${planId}/secondary-check`, { method: 'POST', body: payload, ...opt(facilityId) }),

  roApproval: (planId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-plans/${planId}/ro-approval`, { method: 'POST', body: payload, ...opt(facilityId) }),

  listFractions: (planId: string, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-plans/${planId}/fractions`, opt(facilityId)),

  deliverFraction: (fractionId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-fractions/${fractionId}/deliver`, { method: 'POST', body: payload, ...opt(facilityId) }),

  listMachines: (facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-machines`, opt(facilityId)),

  storeMachine: (payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/rt-machines`, { method: 'POST', body: payload, ...opt(facilityId) }),

  stats: (facilityId?: string | null) =>
    api.request('/api/v1/oncology/stats', opt(facilityId)),

  listMdtReviews: (profileId: string, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/profiles/${profileId}/mdt-reviews`, opt(facilityId)),

  storeMdtReview: (profileId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/profiles/${profileId}/mdt-reviews`, { method: 'POST', body: payload, ...opt(facilityId) }),
};

/* ------------------------------------------------------------------
   Patient Portal / PHR (Phase 16)
   ------------------------------------------------------------------ */

export const portalApi = {
  me: () => api.request('/api/v1/portal/me'),

  profile: () => api.request('/api/v1/portal/profile'),

  medicalHistory: () => api.request('/api/v1/portal/medical-history'),

  medications: () => api.request('/api/v1/portal/medications'),

  labResults: () => api.request('/api/v1/portal/lab-results'),

  radiologyReports: () => api.request('/api/v1/portal/radiology-reports'),

  prescriptions: () => api.request('/api/v1/portal/prescriptions'),

  documents: () => api.request('/api/v1/portal/documents'),

  referrals: () => api.request('/api/v1/portal/referrals'),

  immunizations: () => api.request('/api/v1/portal/immunizations'),

  appointments: () => api.request('/api/v1/portal/appointments'),

  bills: () => api.request('/api/v1/portal/bills'),

  grants: () => api.request('/api/v1/portal/grants'),

  revokeGrant: (grantId: string) =>
    api.request(`/api/v1/portal/grants/${grantId}/revoke`, { method: 'POST', body: {} }),

  messages: () => api.request('/api/v1/portal/messages'),

  sendMessage: (payload: { recipientStaffId: string; subject: string; body: string; category?: string }) =>
    api.request('/api/v1/portal/messages', { method: 'POST', body: payload }),

  notificationPreferences: () => api.request('/api/v1/portal/notification-preferences'),

  updateNotificationPreferences: (payload: Record<string, unknown>) =>
    api.request('/api/v1/portal/notification-preferences', { method: 'PUT', body: payload }),

  consentRecords: () => api.request('/api/v1/portal/consents'),

  revokeConsent: (consentId: string, reason?: string) =>
    api.request('/api/v1/portal/consents/revoke', { method: 'POST', body: { consentId, reason } }),
};
