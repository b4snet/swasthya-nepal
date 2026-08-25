import { api, type RequestOptions } from './client';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });
import type {
  Dashboard, KpiDefinition, KpiMetric, ReportRun, ReportTemplate,
} from './types';

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

export const realtimeApi = {
  events: (params?: { facilityId?: string; category?: string; severity?: string; limit?: number; offset?: number }) => {
    const qs = params ? '?' + new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== undefined && v !== null).map(([k, v]) => [k, String(v)])
    ).toString() : '';
    return api.request<{ events: Array<Record<string, unknown>>; total: number; unreadCount: number }>(`/api/v1/realtime/events${qs}`);
  },
  unreadCount: (facilityId?: string) => {
    const qs = facilityId ? `?facilityId=${facilityId}` : '';
    return api.request<{ count: number }>(`/api/v1/realtime/unread-count${qs}`);
  },
  severityCounts: (facilityId?: string) => {
    const qs = facilityId ? `?facilityId=${facilityId}` : '';
    return api.request<Record<string, number>>(`/api/v1/realtime/severity-counts${qs}`);
  },
  markRead: (eventIds: string[]) =>
    api.request<{ markedCount: number }>('/api/v1/realtime/events/mark-read', { method: 'POST', body: { eventIds } }),
  markAllRead: (facilityId?: string) => {
    const qs = facilityId ? `?facilityId=${facilityId}` : '';
    return api.request<{ markedCount: number }>(`/api/v1/realtime/events/mark-all-read${qs}`, { method: 'POST', body: {} });
  },
  acknowledge: (eventId: string, note?: string) =>
    api.request<Record<string, unknown>>(`/api/v1/realtime/events/${eventId}/acknowledge`, { method: 'POST', body: { note } }),
  dismiss: (eventId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/realtime/events/${eventId}/dismiss`, { method: 'POST', body: {} }),
  stream: () => new EventSource('/api/v1/realtime/stream'),
};
