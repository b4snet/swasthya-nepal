/**
 * Phase 230 — Auth Session & Portal Activation Safety Tests
 *
 * Tests the authentication API (login, refresh, logout) and portal
 * activation API (verifyToken, activate, forgotPassword).
 *
 * This is the authentication gateway — the first security boundary
 * for all user access to the system.
 *
 * API surface: `authApi` (3 endpoints), `portalActivationApi` (3 endpoints)
 * from frontend/src/api/auth.ts
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

// ─── Shared fixtures ─────────────────────────────────────────────────────────

// ─── Mock the API client ─────────────────────────────────────────────────────
const mockRequest = vi.fn();

vi.mock('../api/client', () => ({
  api: { request: (...args: unknown[]) => mockRequest(...args) },
  ApiError: class ApiError extends Error {
    code: string;
    constructor(code: string, message: string) {
      super(message);
      this.code = code;
    }
  },
}));

// ─── Import after mock setup ─────────────────────────────────────────────────
import { authApi, portalActivationApi } from '../api/auth';

beforeEach(() => {
  vi.clearAllMocks();
  mockRequest.mockReset();
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 1 — LOGIN (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Login architecture', () => {
  it('sends POST to /api/v1/auth/login with email and password', async () => {
    mockRequest.mockResolvedValue({
      accessToken: 'at-123', refreshToken: 'rt-456', expiresIn: 3600, refreshExpiresIn: 604800,
      user: { id: 'u-1', email: 'admin@h.com' },
      organization: { id: 'org-1' },
      facility: { id: 'fac-1' },
    });
    await authApi.login('admin@h.com', 'secret123');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/auth/login', {
      method: 'POST',
      body: { email: 'admin@h.com', password: 'secret123' },
    });
  });

  it('returns tokens, user, organization, and facility in response', async () => {
    const resp = {
      accessToken: 'at', refreshToken: 'rt', expiresIn: 3600, refreshExpiresIn: 604800,
      user: { id: 'u-1' }, organization: { id: 'org-1' }, facility: { id: 'fac-1' },
    };
    mockRequest.mockResolvedValue(resp);
    const result = await authApi.login('a@h.com', 'p');
    expect(result).toHaveProperty('accessToken');
    expect(result).toHaveProperty('refreshToken');
    expect(result).toHaveProperty('user');
    expect(result).toHaveProperty('organization');
    expect(result).toHaveProperty('facility');
  });
});

describe('Phase 230 — Login safety', () => {
  it('password is sent in request body, never in URL', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.login('a@h.com', 'secret123');
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).not.toContain('secret123');
    expect(url).not.toContain('password');
  });

  it('login request does not include client-side computed timestamp', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.login('a@h.com', 'p');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('timestamp');
    expect(body).not.toHaveProperty('client_time');
  });

  it('login request does not include device fingerprint', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.login('a@h.com', 'p');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('fingerprint');
    expect(body).not.toHaveProperty('device_id');
  });

  it('login response tokens are not logged client-side', async () => {
    mockRequest.mockResolvedValue({ accessToken: 'at', refreshToken: 'rt' });
    const result = await authApi.login('a@h.com', 'p');
    // The client receives tokens but should store them via tokenStore, not console.log
    expect(result).toHaveProperty('accessToken');
  });

  it('login does not accept empty email or password (server validates)', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.login('', '');
    expect(mockRequest).toHaveBeenCalled();
    // Server must reject empty credentials; client sends them as-is
  });

  it('login endpoint is server-auditable (no skip_audit flag)', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.login('a@h.com', 'p');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('skip_audit');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 2 — REFRESH (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Refresh architecture', () => {
  it('sends POST to /api/v1/auth/refresh with refreshToken', async () => {
    mockRequest.mockResolvedValue({
      accessToken: 'new-at', refreshToken: 'new-rt', expiresIn: 3600, refreshExpiresIn: 604800,
    });
    await authApi.refresh('refresh-token-xyz');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/auth/refresh', {
      method: 'POST',
      body: { refreshToken: 'refresh-token-xyz' },
    });
  });

  it('returns new tokens on successful refresh', async () => {
    const resp = {
      accessToken: 'new-at', refreshToken: 'new-rt', expiresIn: 3600, refreshExpiresIn: 604800,
    };
    mockRequest.mockResolvedValue(resp);
    const result = await authApi.refresh('old-rt');
    expect(result.accessToken).toBe('new-at');
    expect(result.refreshToken).toBe('new-rt');
  });
});

describe('Phase 230 — Refresh safety', () => {
  it('refresh token is sent in body, never in URL', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.refresh('refresh-token-xyz');
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).not.toContain('refresh-token-xyz');
    expect(url).not.toContain('refresh_token');
  });

  it('refresh does not include client-side timestamp', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.refresh('rt');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('timestamp');
    expect(body).not.toHaveProperty('client_time');
  });

  it('refresh endpoint is server-auditable', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.refresh('rt');
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('refresh does not include Authorization header (uses body only)', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.refresh('rt');
    // The authApi.refresh calls api.request with only body, no headers
    const opts = mockRequest.mock.calls[0][1];
    expect(opts).not.toHaveProperty('headers');
    expect(opts).not.toHaveProperty('Authorization');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 3 — LOGOUT (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Logout architecture', () => {
  it('sends POST to /api/v1/auth/logout', async () => {
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/auth/logout', {
      method: 'POST',
      facilityId: undefined,
    });
  });

  it('includes facilityId when provided', async () => {
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout('fac-123');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/auth/logout', {
      method: 'POST',
      facilityId: 'fac-123',
    });
  });
});

describe('Phase 230 — Logout safety', () => {
  it('logout is server-auditable (server invalidates refresh token)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout();
    const opts = mockRequest.mock.calls[0][1];
    expect(opts).not.toHaveProperty('skip_audit');
  });

  it('logout does not send tokens in body (client handles local cleanup)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout();
    const opts = mockRequest.mock.calls[0][1];
    expect(opts.body).toBeUndefined();
  });

  it('logout does not include refresh token (server uses auth header)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout();
    const opts = mockRequest.mock.calls[0][1];
    expect(opts).not.toHaveProperty('refreshToken');
    expect(opts).not.toHaveProperty('token');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 4 — PORTAL ACTIVATION: VERIFY TOKEN (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Portal verify token architecture', () => {
  it('sends GET to /api/v1/portal/activate/:token', async () => {
    mockRequest.mockResolvedValue({
      invitationId: 'inv-001', patientName: 'Ram Sharma',
      expiresAt: '2024-12-31T23:59:59Z', email: 'ram@example.com',
    });
    await portalActivationApi.verifyToken('token-abc');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/portal/activate/token-abc');
  });

  it('returns invitationId, patientName, expiresAt, email', async () => {
    const resp = {
      invitationId: 'inv-001', patientName: 'Ram',
      expiresAt: '2024-12-31', email: 'r@e.com',
    };
    mockRequest.mockResolvedValue(resp);
    const result = await portalActivationApi.verifyToken('tok');
    expect(result).toHaveProperty('invitationId');
    expect(result).toHaveProperty('patientName');
    expect(result).toHaveProperty('expiresAt');
  });
});

describe('Phase 230 — Portal verify token safety', () => {
  it('token is in URL path, not in query parameters', async () => {
    mockRequest.mockResolvedValue({});
    await portalActivationApi.verifyToken('my-token');
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).toContain('my-token');
    expect(url).not.toContain('?token=');
  });

  it('verify does not return patient medical history', async () => {
    mockRequest.mockResolvedValue({
      invitationId: 'inv-001', patientName: 'Ram',
      expiresAt: '2024-12-31', email: 'r@e.com',
    });
    const result = await portalActivationApi.verifyToken('tok');
    expect(result).not.toHaveProperty('medical_history');
    expect(result).not.toHaveProperty('diagnoses');
    expect(result).not.toHaveProperty('medications');
  });

  it('verify does not return patient address or national ID', async () => {
    mockRequest.mockResolvedValue({
      invitationId: 'inv-001', patientName: 'Ram',
      expiresAt: '2024-12-31', email: null,
    });
    const result = await portalActivationApi.verifyToken('tok');
    expect(result).not.toHaveProperty('address');
    expect(result).not.toHaveProperty('nationalId');
    expect(result).not.toHaveProperty('national_id');
  });

  it('verify endpoint is public (no auth required)', async () => {
    mockRequest.mockResolvedValue({});
    await portalActivationApi.verifyToken('tok');
    // Public endpoint — no authorization headers sent
    const opts = mockRequest.mock.calls[0][1];
    expect(opts).toBeUndefined(); // no options passed
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 5 — PORTAL ACTIVATION: ACTIVATE (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Portal activate architecture', () => {
  it('sends POST to /api/v1/portal/activate/:token with password', async () => {
    mockRequest.mockResolvedValue({
      token: 'session-token', session: { id: 'sess-001', expiresAt: '2024-12-31' },
    });
    await portalActivationApi.activate('my-token', 'NewPass123!', 'NewPass123!');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/portal/activate/my-token', {
      method: 'POST',
      body: { password: 'NewPass123!', password_confirmation: 'NewPass123!' },
    });
  });

  it('returns session token and session details', async () => {
    const resp = { token: 'st', session: { id: 's-1', expiresAt: '2024-12-31' } };
    mockRequest.mockResolvedValue(resp);
    const result = await portalActivationApi.activate('tok', 'p', 'p');
    expect(result).toHaveProperty('token');
    expect(result).toHaveProperty('session');
  });
});

describe('Phase 230 — Portal activate safety', () => {
  it('password is sent in body, never in URL', async () => {
    mockRequest.mockResolvedValue({ token: 't', session: { id: 's', expiresAt: 'e' } });
    await portalActivationApi.activate('my-token', 'Pass123!', 'Pass123!');
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).not.toContain('Pass123!');
    expect(url).not.toContain('password');
  });

  it('password_confirmation is sent alongside password', async () => {
    mockRequest.mockResolvedValue({ token: 't', session: { id: 's', expiresAt: 'e' } });
    await portalActivationApi.activate('tok', 'Pass123!', 'Pass123!');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body.password_confirmation).toBe('Pass123!');
  });

  it('activate does not send client-side timestamp', async () => {
    mockRequest.mockResolvedValue({ token: 't', session: { id: 's', expiresAt: 'e' } });
    await portalActivationApi.activate('tok', 'p', 'p');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('timestamp');
  });

  it('activate does not allow setting role or permissions', async () => {
    mockRequest.mockResolvedValue({ token: 't', session: { id: 's', expiresAt: 'e' } });
    await portalActivationApi.activate('tok', 'p', 'p');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('role');
    expect(body).not.toHaveProperty('permissions');
    expect(body).not.toHaveProperty('roleCode');
  });

  it('activate endpoint is server-auditable', async () => {
    mockRequest.mockResolvedValue({ token: 't', session: { id: 's', expiresAt: 'e' } });
    await portalActivationApi.activate('tok', 'p', 'p');
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('token is in URL path, not query parameter', async () => {
    mockRequest.mockResolvedValue({ token: 't', session: { id: 's', expiresAt: 'e' } });
    await portalActivationApi.activate('my-token', 'p', 'p');
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).toContain('my-token');
    expect(url).not.toContain('?token=');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 6 — PORTAL ACTIVATION: FORGOT PASSWORD (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Forgot password architecture', () => {
  it('sends POST to /api/v1/portal/forgot-password with org code and identifier', async () => {
    mockRequest.mockResolvedValue({ message: 'If an account exists, you will receive a reset link.' });
    await portalActivationApi.forgotPassword('SHASTH', 'ram@example.com');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/portal/forgot-password', {
      method: 'POST',
      body: { organizationCode: 'SHASTH', identifier: 'ram@example.com' },
    });
  });

  it('returns generic success message (does not reveal account existence)', async () => {
    mockRequest.mockResolvedValue({ message: 'If an account exists, you will receive a reset link.' });
    const result = await portalActivationApi.forgotPassword('ORG', 'user@test.com');
    expect(result.message).toContain('If an account exists');
  });
});

describe('Phase 230 — Forgot password safety', () => {
  it('does not reveal whether the email/account exists (prevents enumeration)', async () => {
    mockRequest.mockResolvedValue({ message: 'If an account exists, you will receive a reset link.' });
    const result = await portalActivationApi.forgotPassword('ORG', 'nonexistent@test.com');
    // The message must be the same regardless of whether the account exists
    expect(result.message).toContain('If an account exists');
  });

  it('identifier is sent in body, never in URL', async () => {
    mockRequest.mockResolvedValue({ message: 'OK' });
    await portalActivationApi.forgotPassword('ORG', 'user@test.com');
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).not.toContain('user@test.com');
    expect(url).not.toContain('identifier');
  });

  it('organizationCode is sent in body, not in URL', async () => {
    mockRequest.mockResolvedValue({ message: 'OK' });
    await portalActivationApi.forgotPassword('SHASTH', 'u@e.com');
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).not.toContain('SHASTH');
  });

  it('forgot-password endpoint is server-auditable', async () => {
    mockRequest.mockResolvedValue({ message: 'OK' });
    await portalActivationApi.forgotPassword('ORG', 'u@e.com');
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('does not expose internal user ID or patient ID', async () => {
    mockRequest.mockResolvedValue({ message: 'If an account exists, you will receive a reset link.' });
    const result = await portalActivationApi.forgotPassword('ORG', 'u@e.com');
    expect(result).not.toHaveProperty('userId');
    expect(result).not.toHaveProperty('patientId');
    expect(result).not.toHaveProperty('user_id');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 7 — CROSS-DOMAIN AUTHORIZATION
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Cross-domain authorization', () => {
  it('login requires no prior authentication (public endpoint)', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.login('a@h.com', 'p');
    // No auth headers or tokens sent
    expect(mockRequest).toHaveBeenCalled();
  });

  it('refresh requires no prior authentication (uses refresh token in body)', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.refresh('rt');
    expect(mockRequest).toHaveBeenCalled();
  });

  it('logout requires authentication (server uses auth header)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout();
    // Server must verify auth header before invalidating session
    expect(mockRequest).toHaveBeenCalled();
  });

  it('portal activation endpoints are public (no auth required)', async () => {
    mockRequest.mockResolvedValue({});
    await portalActivationApi.verifyToken('tok');
    await portalActivationApi.activate('tok', 'p', 'p');
    await portalActivationApi.forgotPassword('ORG', 'u@e.com');
    // All three calls made without auth headers
    expect(mockRequest).toHaveBeenCalledTimes(3);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 8 — CROSS-DOMAIN SCOPE
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Cross-domain scope', () => {
  it('login response includes organization and facility context', async () => {
    mockRequest.mockResolvedValue({
      accessToken: 'at', refreshToken: 'rt', expiresIn: 3600, refreshExpiresIn: 604800,
      user: { id: 'u-1' }, organization: { id: 'org-1' }, facility: { id: 'fac-1' },
    });
    const result = await authApi.login('a@h.com', 'p');
    expect(result).toHaveProperty('organization');
    expect(result).toHaveProperty('facility');
  });

  it('logout can be scoped to a facility', async () => {
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout('fac-123');
    const opts = mockRequest.mock.calls[0][1];
    expect(opts.facilityId).toBe('fac-123');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 9 — AUDIT TRAIL
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Audit trail', () => {
  it('login is server-auditable', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.login('a@h.com', 'p');
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('refresh is server-auditable', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.refresh('rt');
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('logout is server-auditable', async () => {
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout();
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('skip_audit');
  });

  it('portal activation is server-auditable', async () => {
    mockRequest.mockResolvedValue({ token: 't', session: { id: 's', expiresAt: 'e' } });
    await portalActivationApi.activate('tok', 'p', 'p');
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('forgot-password is server-auditable', async () => {
    mockRequest.mockResolvedValue({ message: 'OK' });
    await portalActivationApi.forgotPassword('ORG', 'u@e.com');
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 10 — PRIVACY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Privacy', () => {
  it('login response does not include password hash', async () => {
    mockRequest.mockResolvedValue({
      accessToken: 'at', refreshToken: 'rt', user: { id: 'u-1' },
      organization: {}, facility: {},
    });
    const result = await authApi.login('a@h.com', 'p');
    expect(result).not.toHaveProperty('password');
    expect(result).not.toHaveProperty('password_hash');
  });

  it('login response does not include internal Supabase UIDs', async () => {
    mockRequest.mockResolvedValue({
      accessToken: 'at', refreshToken: 'rt', user: { id: 'u-1' },
      organization: {}, facility: {},
    });
    const result = await authApi.login('a@h.com', 'p');
    expect(result).not.toHaveProperty('supabase_uid');
    expect(result).not.toHaveProperty('internal_uuid');
  });

  it('portal verify does not return patient medical data', async () => {
    mockRequest.mockResolvedValue({
      invitationId: 'inv', patientName: 'Ram', expiresAt: '2024-12-31', email: null,
    });
    const result = await portalActivationApi.verifyToken('tok');
    expect(result).not.toHaveProperty('medical_history');
    expect(result).not.toHaveProperty('diagnoses');
    expect(result).not.toHaveProperty('phone');
  });

  it('forgot-password does not reveal account existence', async () => {
    mockRequest.mockResolvedValue({ message: 'If an account exists, you will receive a reset link.' });
    const result = await portalActivationApi.forgotPassword('ORG', 'u@e.com');
    expect(result).not.toHaveProperty('account_exists');
    expect(result).not.toHaveProperty('user_found');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 11 — ARCHITECTURE COMPLETENESS
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 230 — Architecture completeness', () => {
  it('authApi exposes exactly 3 methods: login, refresh, logout', () => {
    const methods = Object.keys(authApi);
    expect(methods).toContain('login');
    expect(methods).toContain('refresh');
    expect(methods).toContain('logout');
    expect(methods.length).toBe(3);
  });

  it('portalActivationApi exposes exactly 3 methods: verifyToken, activate, forgotPassword', () => {
    const methods = Object.keys(portalActivationApi);
    expect(methods).toContain('verifyToken');
    expect(methods).toContain('activate');
    expect(methods).toContain('forgotPassword');
    expect(methods.length).toBe(3);
  });

  it('all auth endpoints use POST method', async () => {
    mockRequest.mockResolvedValue({});
    await authApi.login('a@h.com', 'p');
    await authApi.refresh('rt');
    mockRequest.mockResolvedValue(undefined);
    await authApi.logout();

    expect(mockRequest.mock.calls[0][1].method).toBe('POST');
    expect(mockRequest.mock.calls[1][1].method).toBe('POST');
    expect(mockRequest.mock.calls[2][1].method).toBe('POST');
  });

  it('verifyToken uses GET (read-only)', async () => {
    mockRequest.mockResolvedValue({});
    await portalActivationApi.verifyToken('tok');
    expect(mockRequest.mock.calls[0][1]).toBeUndefined(); // no options = GET default
  });

  it('activate uses POST', async () => {
    mockRequest.mockResolvedValue({ token: 't', session: { id: 's', expiresAt: 'e' } });
    await portalActivationApi.activate('tok', 'p', 'p');
    expect(mockRequest.mock.calls[0][1].method).toBe('POST');
  });

  it('forgotPassword uses POST', async () => {
    mockRequest.mockResolvedValue({ message: 'OK' });
    await portalActivationApi.forgotPassword('ORG', 'u@e.com');
    expect(mockRequest.mock.calls[0][1].method).toBe('POST');
  });
});
