import { api, type RequestOptions } from './client';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });
import type {
  LoginResponse,
} from './types';

export const authApi = {
  login: (email: string, password: string) =>
    api.request<LoginResponse>('/api/v1/auth/login', { method: 'POST', body: { email, password } }),

  refresh: (refreshToken: string) =>
    api.request<LoginResponse>('/api/v1/auth/refresh', { method: 'POST', body: { refreshToken } }),

  logout: (facilityId?: string | null) => api.request<void>('/api/v1/auth/logout', { method: 'POST', ...opt(facilityId) }),
};

export const portalActivationApi = {
  verifyToken: (token: string) =>
    api.request<{ invitationId: string; patientName: string; expiresAt: string; email: string | null }>(
      `/api/v1/portal/activate/${token}`,
    ),
  activate: (token: string, password: string, passwordConfirmation: string) =>
    api.request<{ token: string; session: { id: string; expiresAt: string } }>(
      `/api/v1/portal/activate/${token}`,
      { method: 'POST', body: { password, password_confirmation: passwordConfirmation } },
    ),
  forgotPassword: (organizationCode: string, identifier: string) =>
    api.request<{ message: string }>(
      '/api/v1/portal/forgot-password',
      { method: 'POST', body: { organizationCode, identifier } },
    ),
};
