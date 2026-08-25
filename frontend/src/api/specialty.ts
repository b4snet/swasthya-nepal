import { api } from './client';

export const specialtyApi = {
  listProfiles: (departmentId?: string, status?: string) => {
    const params = new URLSearchParams();
    if (departmentId) params.set('departmentId', departmentId);
    if (status) params.set('status', status);
    const qs = params.toString();
    return api.request<Array<{
      id: string; patientId: string; departmentId: string; encounterId: string | null;
      primaryDiagnosis: string | null; diagnosisCode: string | null; status: string;
      clinicalSummary: string | null; customFields: Record<string, unknown> | null;
      diagnosedAt: string | null; createdAt: string;
    }>>('/api/v1/specialty/profiles' + (qs ? '?' + qs : ''));
  },
  storeProfile: (payload: {
    patientId: string; departmentId: string; encounterId?: string;
    primaryDiagnosis?: string; diagnosisCode?: string; clinicalSummary?: string;
    customFields?: Record<string, unknown>; diagnosedAt?: string;
  }) => api.request<{ id: string; patientId: string; departmentId: string }>(
    '/api/v1/specialty/profiles', { method: 'POST', body: payload },
  ),
  showProfile: (id: string) => api.request<{
    profile: { id: string; patientId: string; departmentId: string; primaryDiagnosis: string | null; status: string; clinicalSummary: string | null; customFields: Record<string, unknown> | null; diagnosedAt: string | null };
    assessments: Array<{ id: string; assessmentType: string; status: string; responses: Record<string, unknown> | null; assessedAt: string | null }>;
    carePlans: Array<{ id: string; planName: string; status: string; goals: string[] | null; interventions: string[] | null; startDate: string | null; reviewDate: string | null }>;
  }>('/api/v1/specialty/profiles/' + id),
  storeAssessment: (profileId: string, payload: {
    assessmentType: string; formTemplateId?: string; responses?: Record<string, unknown>; notes?: string;
  }) => api.request<{ id: string; assessmentType: string; status: string }>(
    '/api/v1/specialty/profiles/' + profileId + '/assessments', { method: 'POST', body: payload },
  ),
  storeCarePlan: (profileId: string, payload: {
    planName: string; goals?: string[]; interventions?: string[]; milestones?: string[];
    responsibleStaffId?: string; startDate?: string; targetEndDate?: string; reviewDate?: string;
  }) => api.request<{ id: string; planName: string; status: string }>(
    '/api/v1/specialty/profiles/' + profileId + '/care-plans', { method: 'POST', body: payload },
  ),
  activateCarePlan: (planId: string) => api.request<{ id: string; status: string }>(
    '/api/v1/specialty/care-plans/' + planId + '/activate', { method: 'POST' },
  ),
  completeCarePlan: (planId: string) => api.request<{ id: string; status: string }>(
    '/api/v1/specialty/care-plans/' + planId + '/complete', { method: 'POST' },
  ),
};
