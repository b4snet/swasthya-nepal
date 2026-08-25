import { api } from './client';
import type {
  GeneratedDocument,
} from './types';

export const documentCenterApi = {
  list: (orgId: string, params?: { category?: string; documentType?: string; patientId?: string; status?: string; search?: string }) => {
    const qs = params ? '?' + new URLSearchParams(Object.entries(params).filter(([, v]) => v)).toString() : '';
    return api.request<{ data: GeneratedDocument[]; total: number; page: number; lastPage: number }>(`/api/v1/organizations/${orgId}/documents${qs}`);
  },
  listPlatform: (params?: { category?: string; documentType?: string; patientId?: string; status?: string; search?: string }) => {
    const qs = params ? '?' + new URLSearchParams(Object.entries(params).filter(([, v]) => v)).toString() : '';
    return api.request<{ data: GeneratedDocument[]; total: number; page: number; lastPage: number }>(`/api/v1/documents/platform${qs}`);
  },
  show: (documentId: string) =>
    api.request<GeneratedDocument>(`/api/v1/documents/${documentId}`),
  generate: (orgId: string, payload: Record<string, unknown>) =>
    api.request<GeneratedDocument>(`/api/v1/organizations/${orgId}/documents/generate`, { method: 'POST', body: payload }),
  verify: (documentId: string) =>
    api.request<GeneratedDocument>(`/api/v1/documents/${documentId}/verify`, { method: 'POST', body: {} }),
  sign: (documentId: string) =>
    api.request<GeneratedDocument>(`/api/v1/documents/${documentId}/sign`, { method: 'POST', body: {} }),
  share: (documentId: string) =>
    api.request<GeneratedDocument>(`/api/v1/documents/${documentId}/share`, { method: 'POST', body: {} }),
  categories: () =>
    api.request<{ types: Record<string, string>; categories: Record<string, string> }>('/api/v1/documents/categories'),
  stats: (orgId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/organizations/${orgId}/documents/stats`),

  /** Get the PDF download URL for a document */
  pdfUrl: (documentId: string) => `/api/v1/documents/${documentId}/pdf`,

  /** Regenerate the PDF for a document (force re-render) */
  regeneratePdf: (documentId: string) =>
    api.request<{ pdfPath: string; pageCount: number; sizeBytes: number }>(`/api/v1/documents/${documentId}/pdf`, { method: 'POST', body: {} }),
};
