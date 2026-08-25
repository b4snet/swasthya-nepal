import { api } from './client';

export const admissionApi = {
  store: (encounterId: string, payload: {
    bedId: string;
    admissionType: string;
    admittingDiagnosis: string;
  }) => api.request<{
    id: string; patientId: string; encounterId: string;
    admissionNumber: string; status: string; admittedAt: string;
  }>(`/api/v1/encounters/${encounterId}/admissions`, { method: 'POST', body: payload }),
  show: (admissionId: string) => api.request<{
    id: string; patientId: string; encounterId: string;
    admissionType: string; admittingDiagnosis: string | null;
    status: string; admittedAt: string; dischargedAt: string | null;
    dischargeType: string | null; dischargeSummary: string | null;
    bed: { id: string; bedCode: string } | null;
  }>(`/api/v1/admissions/${admissionId}`),
  transfer: (admissionId: string, payload: {
    targetBedId: string;
    reason: string;
  }) => api.request<{
    id: string; status: string;
    oldBed: { id: string; bedCode: string };
    newBed: { id: string; bedCode: string };
  }>(`/api/v1/admissions/${admissionId}/transfer`, { method: 'POST', body: payload }),
  discharge: (admissionId: string, payload: {
    dischargeType: string;
    dischargeSummary?: string;
  }) => api.request<{
    id: string; status: string; dischargedAt: string;
  }>(`/api/v1/admissions/${admissionId}/discharge`, { method: 'POST', body: payload }),
};

export const bedWardApi = {
  occupancy: (orgId: string) =>
    api.request<{ summary: Record<string, number>; wards: Array<{
      id: string; name: string; wardType: string;
      counts: Record<string, number>;
      rooms: Array<{
        id: string; name: string; roomType: string;
        counts: Record<string, number>;
        beds: Array<{ id: string; bedCode: string; status: string; lockVersion: number; admissionId: string | null }>;
      }>;
    }> }>(`/api/v1/organizations/${orgId}/beds/occupancy`),
  list: (orgId: string) =>
    api.request<Array<{
      id: string; facilityId: string; roomId: string; room: { id: string; code: string; name: string } | null;
      bedCode: string; status: string; lockVersion: number; currentAdmissionId: string | null;
    }>>(`/api/v1/organizations/${orgId}/beds`),
  updateStatus: (bedId: string, payload: { status: string; lockVersion: number }) =>
    api.request<{ id: string; status: string; lockVersion: number }>(
      `/api/v1/beds/${bedId}`, { method: 'PATCH', body: payload },
    ),
};

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
  admissions: (status?: string) => {
    const qs = status ? ("?status=" + status) : "";
    return api.request<Array<{
      id: string; patientId: string; icuBedId: string; acuity: string; status: string; source: string | null;
      admittedAt: string | null; nextObservationDueAt: string | null;
    }>>("/api/v1/icu-admissions" + qs);
  },
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
