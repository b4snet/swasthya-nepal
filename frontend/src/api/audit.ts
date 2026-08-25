import { api, type RequestOptions } from './client';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });
import type {
  AuditEvent,
} from './types';

export const auditApi = {
  list: (params: { limit?: number; facilityId?: string | null }) => {
    const qs = new URLSearchParams();
    if (params.limit) qs.set('limit', String(params.limit));
    return api.request<AuditEvent[]>(`/api/v1/audit-events?${qs}`, opt(params.facilityId));
  },
};
