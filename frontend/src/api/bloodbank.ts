import { api } from './client';

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
  units: (status?: string) => {
    const qs = status ? '?status=' + status : '';
    return api.request<Array<{
      id: string; unitNumber: string; componentType: string; bloodGroup: string;
      rhFactor: string; expiryAt: string | null; tested: boolean; status: string;
    }>>('/api/v1/blood-units' + qs);
  },
  transfusions: (status?: string) => {
    const qs = status ? '?status=' + status : '';
    return api.request<Array<{
      id: string; bloodUnitId: string; patientId: string; status: string;
      startedAt: string | null; verifiedAt: string | null; stoppedAt: string | null;
      volumeTransfusedMl: number | null;
    }>>('/api/v1/transfusions' + qs);
  },
};
