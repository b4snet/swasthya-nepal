import { api, type RequestOptions } from './client';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });
import type {
  LoginResponse,
} from './types';

export const authApi = {
  login: (email: string, password: string) =>
    api.request<LoginResponse>('/api/v1/auth/login', { method: 'POST', body: { email, password } }),

  // Refresh uses the httpOnly swasthya_refresh cookie — the browser sends it
  // automatically on same-origin requests. No body is needed; the backend
  // reads from the cookie (SECURITY.md §4, §23). noRefresh: a 401 here means
  // the cookie is expired — re-triggering the auto-refresh would loop.
  refresh: () =>
    api.request<LoginResponse>('/api/v1/auth/refresh', { method: 'POST', credentials: 'same-origin', noRefresh: true }),

  logout: (facilityId?: string | null) => api.request<void>('/api/v1/auth/logout', { method: 'POST', ...opt(facilityId) }),

  // Staff password reset (SECURITY.md §2, §5): the forgot response is generic
  // on purpose to avoid account enumeration; the reset token is single-use and
  // short-lived, delivered by email.
  forgotPassword: (email: string) =>
    api.request<{ message: string }>(
      '/api/v1/auth/password/forgot',
      { method: 'POST', body: { email } },
    ),
  resetPassword: (token: string, password: string) =>
    api.request<{ message: string }>(
      '/api/v1/auth/password/reset',
      { method: 'POST', body: { token, password } },
    ),
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
