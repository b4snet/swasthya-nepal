import { api, type RequestOptions } from './client';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

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

  showTreatmentPlan: (planId: string, facilityId?: string | null) =>
    api.request(`/api/v1/oncology/treatment-plans/${planId}`, opt(facilityId)),

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
