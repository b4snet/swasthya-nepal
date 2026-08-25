import { api, ApiError, type RequestOptions } from './client';
import type {
  Referral,
  Appointment, AvailabilitySlot, ClinicalNote, Diagnosis, Encounter, FollowUp, FollowUpReminder, PharmacyPrescription, QueueEntry, Staff,
} from './types';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

function orgUrl(organizationId: string | null | undefined): string {
  if (!organizationId) {
    throw new ApiError('NO_TENANT_CONTEXT', 'Organization context is required for this request.');
  }
  return `/api/v1/organizations/${organizationId}`;
}

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

export const doctorScheduleApi = {
  listDoctors: (organizationId: string, facilityId?: string | null, params?: Record<string, string>) => {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.request<Staff[]>(`${orgUrl(organizationId)}/doctors${qs}`, opt(facilityId));
  },
  weeklySchedule: (staffId: string, weekStart?: string) => {
    const qs = weekStart ? `?weekStart=${weekStart}` : '';
    return api.request<Record<string, unknown>>(`/api/v1/doctors/${staffId}/weekly-schedule${qs}`);
  },
  updateWeeklySchedule: (organizationId: string, staffId: string, schedule: Record<string, unknown>[]) =>
    api.request<{ created: number }>(`${orgUrl(organizationId)}/doctors/${staffId}/weekly-schedule`, { method: 'POST', body: { schedule } }),
  departmentSchedule: (organizationId: string, departmentId: string, date?: string) => {
    const qs = date ? `?date=${date}` : '';
    return api.request<Record<string, unknown>>(`${orgUrl(organizationId)}/departments/${departmentId}/schedule${qs}`);
  },
};

export const encountersApi = {
  start: (appointmentId: string, facilityId?: string | null) =>
    api.request<Encounter>(`/api/v1/appointments/${appointmentId}/start-encounter`, { method: 'POST', body: {}, ...opt(facilityId) }),

  show: (id: string, facilityId?: string | null) => api.request<Encounter>(`/api/v1/encounters/${id}`, opt(facilityId)),

  forPatient: (patientId: string, facilityId?: string | null) =>
    api.request<Array<{ id: string; type: string; status: string; providerName: string; serviceName: string; startedAt: string }>>(`/api/v1/patients/${patientId}/encounters`, opt(facilityId)),

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

export const referralsApi = {
  list: (params: { patientId?: string; status?: string; page?: number; perPage?: number; facilityId?: string | null }) => {
    const qs = new URLSearchParams();
    if (params.patientId) qs.set('patient_id', params.patientId);
    if (params.status) qs.set('status', params.status);
    if (params.page) qs.set('page', String(params.page));
    if (params.perPage) qs.set('per_page', String(params.perPage));
    return api.request<{ data: Referral[]; current_page: number; last_page: number; total: number }>(
      `/api/v1/referrals?${qs}`,
      opt(params.facilityId),
    );
  },

  show: (id: string, facilityId?: string | null) =>
    api.request<Referral>(`/api/v1/referrals/${id}`, opt(facilityId)),

  create: (payload: {
    patient_id: string;
    encounter_id?: string;
    receiving_staff_id?: string;
    receiving_facility_name?: string;
    receiving_department?: string;
    reason: string;
    clinical_summary?: string;
    urgency?: string;
    specialty?: string;
  }, facilityId?: string | null) =>
    api.request<Referral>('/api/v1/referrals', { method: 'POST', body: payload, facilityId }),

  accept: (id: string, facilityId?: string | null) =>
    api.request<Referral>(`/api/v1/referrals/${id}/accept`, { method: 'POST', body: {}, facilityId }),

  reject: (id: string, rejectionReason: string, facilityId?: string | null) =>
    api.request<Referral>(`/api/v1/referrals/${id}/reject`, { method: 'POST', body: { rejection_reason: rejectionReason }, facilityId }),

  schedule: (id: string, appointmentId: string, facilityId?: string | null) =>
    api.request<Referral>(`/api/v1/referrals/${id}/schedule`, { method: 'POST', body: { appointment_id: appointmentId }, facilityId }),

  complete: (id: string, completionNotes?: string, facilityId?: string | null) =>
    api.request<Referral>(`/api/v1/referrals/${id}/complete`, { method: 'POST', body: { completion_notes: completionNotes }, facilityId }),

  cancel: (id: string, cancellationReason?: string, facilityId?: string | null) =>
    api.request<Referral>(`/api/v1/referrals/${id}/cancel`, { method: 'POST', body: { cancellation_reason: cancellationReason }, facilityId }),
};
