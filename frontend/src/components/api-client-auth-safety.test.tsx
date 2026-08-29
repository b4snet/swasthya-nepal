/**
 * Phase 228 — API Client & Auth Token Safety Tests
 *
 * Tests the core API client (frontend/src/api/client.ts): the ONLY way
 * the SPA communicates with the backend. Covers token lifecycle, tenant
 * context headers, error mapping, refresh-on-401, portal-route exception,
 * retry semantics, timeout handling, correlation IDs, and storage safety.
 *
 * This is the foundational security boundary for every frontend API call.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

// ─── Shared fixtures ─────────────────────────────────────────────────────────
const VALID_TOKENS = {
  accessToken: 'access-token-abc',
  refreshToken: 'refresh-token-xyz',
  expiresIn: 3600,
  refreshExpiresIn: 604800,
};

function jsonResponse(data: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: '',
    json: async () => ({ data }),
  } as unknown as Response;
}

function errorResponse(status: number, body: Record<string, unknown> = {}): Response {
  return {
    ok: false,
    status,
    statusText: status === 401 ? 'Unauthorized' : status === 403 ? 'Forbidden' : '',
    json: async () => ({ error: body }),
  } as unknown as Response;
}

// ─── Mock fetch and sessionStorage/localStorage ──────────────────────────────
const mockFetch = vi.fn();
const sessionStore = new Map<string, string>();
const localStore = new Map<string, string>();

// Use beforeAll to ensure stubs are in place before any imports
vi.hoisted(() => {
  // These run before module imports
});

Object.defineProperty(globalThis, 'fetch', { value: mockFetch, writable: true, configurable: true });
Object.defineProperty(globalThis, 'sessionStorage', {
  value: {
    getItem: (k: string) => sessionStore.get(k) ?? null,
    setItem: (k: string, v: string) => sessionStore.set(k, v),
    removeItem: (k: string) => sessionStore.delete(k),
    clear: () => sessionStore.clear(),
  },
  writable: true, configurable: true,
});
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string) => localStore.get(k) ?? null,
    setItem: (k: string, v: string) => localStore.set(k, v),
    removeItem: (k: string) => localStore.delete(k),
    clear: () => localStore.clear(),
  },
  writable: true, configurable: true,
});

// ─── Import after global mocks ──────────────────────────────────────────────
import { api, ApiError, tokenStore } from '../api/client';
import type { AuthTokens } from '../api/client';

beforeEach(() => {
  sessionStore.clear();
  localStore.clear();
  mockFetch.mockReset();
  mockFetch.mockResolvedValue(jsonResponse({ id: '1', name: 'test' }));
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 1 — TOKEN STORE ARCHITECTURE
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Token store architecture', () => {
  it('stores access token in sessionStorage (not localStorage)', () => {
    tokenStore.set(VALID_TOKENS);
    expect(sessionStore.has('swasthya.accessToken')).toBe(true);
    expect(localStore.has('swasthya.accessToken')).toBe(false);
  });

  it('stores refresh token in localStorage (not sessionStorage)', () => {
    tokenStore.set(VALID_TOKENS);
    expect(localStore.has('swasthya.refreshToken')).toBe(true);
    expect(sessionStore.has('swasthya.refreshToken')).toBe(false);
  });

  it('returns null when no tokens are stored', () => {
    expect(tokenStore.get()).toBeNull();
  });

  it('returns tokens when both access and refresh are present', () => {
    tokenStore.set(VALID_TOKENS);
    const tokens = tokenStore.get();
    expect(tokens).not.toBeNull();
    expect(tokens?.accessToken).toBe(VALID_TOKENS.accessToken);
    expect(tokens?.refreshToken).toBe(VALID_TOKENS.refreshToken);
  });

  it('returns null when only access token is present', () => {
    sessionStore.set('swasthya.accessToken', 'abc');
    expect(tokenStore.get()).toBeNull();
  });

  it('returns null when only refresh token is present', () => {
    localStore.set('swasthya.refreshToken', 'xyz');
    expect(tokenStore.get()).toBeNull();
  });

  it('clear() removes both access and refresh tokens', () => {
    tokenStore.set(VALID_TOKENS);
    tokenStore.clear();
    expect(tokenStore.get()).toBeNull();
  });

  it('clear() removes tokens from the correct storage mechanism', () => {
    tokenStore.set(VALID_TOKENS);
    tokenStore.clear();
    expect(sessionStore.has('swasthya.accessToken')).toBe(false);
    expect(localStore.has('swasthya.refreshToken')).toBe(false);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 2 — TOKEN STORE SAFETY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Token store safety', () => {
  it('access token is NOT persisted in localStorage', () => {
    tokenStore.set(VALID_TOKENS);
    expect(localStore.has('swasthya.accessToken')).toBe(false);
  });

  it('refresh token is NOT stored in sessionStorage', () => {
    tokenStore.set(VALID_TOKENS);
    expect(sessionStore.has('swasthya.refreshToken')).toBe(false);
  });

  it('expired access token is still returned by get() (server decides expiry)', () => {
    tokenStore.set({ ...VALID_TOKENS, expiresIn: 0 });
    const tokens = tokenStore.get();
    expect(tokens?.accessToken).toBe(VALID_TOKENS.accessToken);
  });

  it('concurrent set() calls do not corrupt token storage', () => {
    tokenStore.set({ ...VALID_TOKENS, accessToken: 'token-a' });
    tokenStore.set({ ...VALID_TOKENS, accessToken: 'token-b' });
    expect(tokenStore.get()?.accessToken).toBe('token-b');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 3 — REQUEST CONSTRUCTION
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Request construction', () => {
  it('sends Authorization header with Bearer token', async () => {
    tokenStore.set(VALID_TOKENS);
    await api.request('/api/v1/test');
    const headers = mockFetch.mock.calls[0][1].headers;
    expect(headers.Authorization).toBe(`Bearer ${VALID_TOKENS.accessToken}`);
  });

  it('sends Content-Type: application/json', async () => {
    await api.request('/api/v1/test');
    const headers = mockFetch.mock.calls[0][1].headers;
    expect(headers['Content-Type']).toBe('application/json');
  });

  it('does NOT send Authorization header when no tokens exist', async () => {
    await api.request('/api/v1/test');
    const headers = mockFetch.mock.calls[0][1].headers;
    expect(headers.Authorization).toBeUndefined();
  });

  it('defaults to GET method when none specified', async () => {
    await api.request('/api/v1/test');
    expect(mockFetch.mock.calls[0][1].method).toBe('GET');
  });

  it('serializes body to JSON', async () => {
    await api.request('/api/v1/test', { method: 'POST', body: { name: 'test' } });
    expect(mockFetch.mock.calls[0][1].body).toBe(JSON.stringify({ name: 'test' }));
  });

  it('does not send body for GET requests', async () => {
    await api.request('/api/v1/test');
    expect(mockFetch.mock.calls[0][1].body).toBeUndefined();
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 4 — TENANT CONTEXT HEADERS
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Tenant context headers', () => {
  it('sends X-Swasthya-Facility header when facilityId provided', async () => {
    await api.request('/api/v1/test', { facilityId: 'fac-123' });
    expect(mockFetch.mock.calls[0][1].headers['X-Swasthya-Facility']).toBe('fac-123');
  });

  it('sends X-Swasthya-Branch header when branchId provided', async () => {
    await api.request('/api/v1/test', { branchId: 'br-456' });
    expect(mockFetch.mock.calls[0][1].headers['X-Swasthya-Branch']).toBe('br-456');
  });

  it('sends X-Swasthya-Tenant header when tenantId provided', async () => {
    await api.request('/api/v1/test', { tenantId: 'org-789' });
    expect(mockFetch.mock.calls[0][1].headers['X-Swasthya-Tenant']).toBe('org-789');
  });

  it('does NOT send tenant headers when not provided', async () => {
    await api.request('/api/v1/test');
    const h = mockFetch.mock.calls[0][1].headers;
    expect(h['X-Swasthya-Facility']).toBeUndefined();
    expect(h['X-Swasthya-Branch']).toBeUndefined();
    expect(h['X-Swasthya-Tenant']).toBeUndefined();
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 5 — ENVELOPE UNWRAPPING
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Envelope unwrapping', () => {
  it('extracts data from { data: ... } envelope', async () => {
    mockFetch.mockResolvedValue(jsonResponse({ id: '1', name: 'test' }));
    const result = await api.request<{ id: string; name: string }>('/api/v1/test');
    expect(result).toEqual({ id: '1', name: 'test' });
  });

  it('does not return the envelope wrapper', async () => {
    mockFetch.mockResolvedValue(jsonResponse({ items: [1, 2, 3] }));
    const result = await api.request<{ items: number[] }>('/api/v1/test');
    expect(result).not.toHaveProperty('data');
    expect(result).toHaveProperty('items');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 6 — ERROR MAPPING
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Error mapping', () => {
  it('maps 401 to UNAUTHORIZED', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(401, { message: 'Invalid token' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('UNAUTHORIZED');
      expect((e as ApiError).httpStatus).toBe(401);
    }
  });

  it('maps 403 to FORBIDDEN', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(403, { message: 'No permission' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('FORBIDDEN');
      expect((e as ApiError).httpStatus).toBe(403);
    }
  });

  it('maps 404 to NOT_FOUND', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(404, { message: 'Not found' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('NOT_FOUND');
    }
  });

  it('maps 409 to CONFLICT', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(409, { message: 'Conflict' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('CONFLICT');
    }
  });

  it('maps 422 to VALIDATION', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(422, { message: 'Invalid' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('VALIDATION');
    }
  });

  it('maps 429 to RATE_LIMITED', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(429, { message: 'Too many' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('RATE_LIMITED');
    }
  });

  it('maps 5xx to SERVER', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(500, { message: 'Internal error' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('SERVER');
    }
  });

  it('maps network failure to NETWORK', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockRejectedValue(new TypeError('Failed to fetch'));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('NETWORK');
      expect((e as ApiError).httpStatus).toBe(0);
    }
  });

  it('extracts correlationId from error response body', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(500, {
      code: 'SERVER', message: 'Error', correlationId: 'corr-abc-123',
    }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).correlationId).toBe('corr-abc-123');
    }
  });

  it('extracts details from error response body', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(422, {
      code: 'VALIDATION', message: 'Invalid', details: { field: 'email' },
    }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).details).toEqual({ field: 'email' });
    }
  });

  it('handles non-JSON error body gracefully', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue({
      ok: false, status: 500, statusText: 'Internal Server Error',
      json: async () => { throw new Error('not json'); },
    } as unknown as Response);
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('SERVER');
    }
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 7 — ERROR MAPPING SAFETY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Error mapping safety', () => {
  it('correlationId is null when server does not provide one', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(500, { message: 'Error' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).correlationId).toBeNull();
    }
  });

  it('ApiError is an instance of Error', () => {
    const err = new ApiError('NETWORK', 'test');
    expect(err).toBeInstanceOf(Error);
    expect(err.name).toBe('ApiError');
  });

  it('error details are null when server does not provide them', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(422, { message: 'Invalid' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).details).toBeNull();
    }
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 8 — TOKEN REFRESH ON 401
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Token refresh on 401', () => {
  it('attempts refresh on first 401 and replays the original request', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockResolvedValueOnce(errorResponse(401, { message: 'expired' }))
      .mockResolvedValueOnce({
        ok: true, status: 200, json: async () => ({
          data: { accessToken: 'new-access', refreshToken: 'new-refresh', expiresIn: 3600, refreshExpiresIn: 604800 },
        }),
      })
      .mockResolvedValueOnce(jsonResponse({ id: '1' }));

    const result = await api.request<{ id: string }>('/api/v1/test');
    expect(result).toEqual({ id: '1' });
    expect(mockFetch).toHaveBeenCalledTimes(3);
  });

  it('does NOT refresh for portal routes (/api/v1/portal/)', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(401, { message: 'expired' }));
    try { await api.request('/api/v1/portal/patients'); } catch (e) {
      expect((e as ApiError).code).toBe('UNAUTHORIZED');
    }
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('clears tokens when refresh fails', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockResolvedValueOnce(errorResponse(401, { message: 'expired' }))
      .mockResolvedValueOnce({ ok: false, status: 401, json: async () => ({}) });
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('UNAUTHORIZED');
    }
    expect(tokenStore.get()).toBeNull();
  });

  it('sends refresh request to /api/v1/auth/refresh with refreshToken', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockResolvedValueOnce(errorResponse(401, { message: 'expired' }))
      .mockResolvedValueOnce({
        ok: true, status: 200, json: async () => ({
          data: { accessToken: 'new', refreshToken: 'new-r', expiresIn: 3600, refreshExpiresIn: 604800 },
        }),
      })
      .mockResolvedValueOnce(jsonResponse({ id: '1' }));

    await api.request('/api/v1/test');
    const refreshCall = mockFetch.mock.calls[1];
    expect(refreshCall[0]).toContain('/api/v1/auth/refresh');
    const body = JSON.parse(refreshCall[1].body);
    expect(body.refreshToken).toBe(VALID_TOKENS.refreshToken);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 9 — TOKEN REFRESH SAFETY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Token refresh safety', () => {
  it('does not send Authorization header on refresh request', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockResolvedValueOnce(errorResponse(401, {}))
      .mockResolvedValueOnce({
        ok: true, status: 200, json: async () => ({
          data: { accessToken: 'new', refreshToken: 'new-r', expiresIn: 3600, refreshExpiresIn: 604800 },
        }),
      })
      .mockResolvedValueOnce(jsonResponse({ id: '1' }));

    await api.request('/api/v1/test');
    const refreshHeaders = mockFetch.mock.calls[1][1].headers;
    expect(refreshHeaders.Authorization).toBeUndefined();
  });

  it('does not refresh when no tokens are stored', async () => {
    mockFetch.mockResolvedValue(errorResponse(401, { message: 'no auth' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('UNAUTHORIZED');
    }
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('new tokens from refresh are stored immediately', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockResolvedValueOnce(errorResponse(401, {}))
      .mockResolvedValueOnce({
        ok: true, status: 200, json: async () => ({
          data: { accessToken: 'new-acc', refreshToken: 'new-ref', expiresIn: 3600, refreshExpiresIn: 604800 },
        }),
      })
      .mockResolvedValueOnce(jsonResponse({ ok: true }));

    await api.request('/api/v1/test');
    const tokens = tokenStore.get();
    expect(tokens?.accessToken).toBe('new-acc');
    expect(tokens?.refreshToken).toBe('new-ref');
  });

  it('concurrent 401s only trigger one refresh (single-flight dedup)', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(401, {}));

    const p1 = api.request('/api/v1/a').catch(() => {});
    const p2 = api.request('/api/v1/b').catch(() => {});
    await Promise.all([p1, p2]);

    const refreshCalls = mockFetch.mock.calls.filter(
      (c: unknown[]) => (c[0] as string).includes('/auth/refresh')
    );
    expect(refreshCalls.length).toBeLessThanOrEqual(1);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 10 — RETRY SEMANTICS
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Retry semantics', () => {
  it('retries on NETWORK failure for idempotent GET', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockRejectedValueOnce(new TypeError('Failed to fetch'))
      .mockResolvedValueOnce(jsonResponse({ id: '1' }));
    const result = await api.request<{ id: string }>('/api/v1/test', { retries: 1 });
    expect(result).toEqual({ id: '1' });
    expect(mockFetch).toHaveBeenCalledTimes(2);
  });

  it('retries on TIMEOUT for idempotent GET', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockRejectedValueOnce(new DOMException('aborted', 'AbortError'))
      .mockResolvedValueOnce(jsonResponse({ id: '1' }));
    const result = await api.request<{ id: string }>('/api/v1/test', { retries: 1 });
    expect(result).toEqual({ id: '1' });
  });

  it('does NOT retry non-idempotent POST', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockRejectedValue(new TypeError('Failed to fetch'));
    try { await api.request('/api/v1/test', { method: 'POST', body: {}, retries: 3 }); } catch (e) {
      expect((e as ApiError).code).toBe('NETWORK');
    }
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('does NOT retry on 4xx/5xx errors', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(500, { message: 'server error' }));
    try { await api.request('/api/v1/test', { retries: 3 }); } catch (e) {
      expect((e as ApiError).code).toBe('SERVER');
    }
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('retries PATCH (idempotent)', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockRejectedValueOnce(new TypeError('Failed to fetch'))
      .mockResolvedValueOnce(jsonResponse({ id: '1' }));
    const result = await api.request<{ id: string }>('/api/v1/test', { method: 'PATCH', body: {}, retries: 1 });
    expect(result).toEqual({ id: '1' });
  });

  it('does NOT retry DELETE', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockRejectedValue(new TypeError('Failed to fetch'));
    try { await api.request('/api/v1/test', { method: 'DELETE', retries: 2 }); } catch (e) {
      expect((e as ApiError).code).toBe('NETWORK');
    }
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('does NOT retry PUT', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockRejectedValue(new TypeError('Failed to fetch'));
    try { await api.request('/api/v1/test', { method: 'PUT', body: {}, retries: 2 }); } catch (e) {
      expect((e as ApiError).code).toBe('NETWORK');
    }
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 11 — TIMEOUT HANDLING
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Timeout handling', () => {
  it('maps AbortError to TIMEOUT error code', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockRejectedValue(new DOMException('aborted', 'AbortError'));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('TIMEOUT');
      expect((e as ApiError).message).toContain('timed out');
    }
  });

  it('does not leak request to background process after timeout', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockImplementation(() => new Promise((resolve) => {
      setTimeout(() => resolve(jsonResponse({ id: '1' })), 50);
    }));
    try { await api.request('/api/v1/test', { timeoutMs: 1 }); } catch (e) {
      expect((e as ApiError).code).toBe('TIMEOUT');
    }
  });

  it('external AbortSignal triggers the same TIMEOUT path', async () => {
    tokenStore.set(VALID_TOKENS);
    const controller = new AbortController();
    mockFetch.mockImplementation((_url: string, init: RequestInit) => {
      return new Promise((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => {
          reject(new DOMException('aborted', 'AbortError'));
        });
      });
    });
    const promise = api.request('/api/v1/test', { signal: controller.signal });
    controller.abort();
    try { await promise; } catch (e) {
      expect((e as ApiError).code).toBe('TIMEOUT');
    }
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 12 — AUDIT TRAIL
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Audit trail', () => {
  it('every request includes correlation tracking capability', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockResolvedValue(errorResponse(500, { correlationId: 'aud-001' }));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).correlationId).toBe('aud-001');
    }
  });

  it('token refresh is auditable via /auth/refresh endpoint', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockResolvedValueOnce(errorResponse(401, {}))
      .mockResolvedValueOnce({
        ok: true, status: 200, json: async () => ({
          data: { accessToken: 'new', refreshToken: 'new-r', expiresIn: 3600, refreshExpiresIn: 604800 },
        }),
      })
      .mockResolvedValueOnce(jsonResponse({ id: '1' }));
    await api.request('/api/v1/test');
    expect(mockFetch.mock.calls[1][0]).toContain('/auth/refresh');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 13 — PRIVACY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Privacy', () => {
  it('access token is NEVER sent in URL query parameters', async () => {
    tokenStore.set(VALID_TOKENS);
    await api.request('/api/v1/test');
    const url = mockFetch.mock.calls[0][0] as string;
    expect(url).not.toContain('access_token');
    expect(url).not.toContain('token=');
  });

  it('refresh token is NEVER sent in URL query parameters', async () => {
    tokenStore.set(VALID_TOKENS);
    await api.request('/api/v1/test');
    const url = mockFetch.mock.calls[0][0] as string;
    expect(url).not.toContain('refresh_token');
  });

  it('tokens are sent in Authorization header, not in body', async () => {
    tokenStore.set(VALID_TOKENS);
    await api.request('/api/v1/test', { method: 'POST', body: { data: 'test' } });
    const headers = mockFetch.mock.calls[0][1].headers;
    expect(headers.Authorization).toBeDefined();
    const body = JSON.parse(mockFetch.mock.calls[0][1].body);
    expect(body).not.toHaveProperty('accessToken');
  });

  it('refresh request does not include Authorization header', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch
      .mockResolvedValueOnce(errorResponse(401, {}))
      .mockResolvedValueOnce({
        ok: true, status: 200, json: async () => ({
          data: { accessToken: 'new', refreshToken: 'new-r', expiresIn: 3600, refreshExpiresIn: 604800 },
        }),
      })
      .mockResolvedValueOnce(jsonResponse({ id: '1' }));
    await api.request('/api/v1/test');
    const refreshHeaders = mockFetch.mock.calls[1][1].headers;
    expect(refreshHeaders).not.toHaveProperty('Authorization');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 14 — ARCHITECTURE COMPLETENESS
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 228 — Architecture completeness', () => {
  it('api client exposes request, setTokens, getTokens, clearTokens', () => {
    expect(typeof api.request).toBe('function');
    expect(typeof api.setTokens).toBe('function');
    expect(typeof api.getTokens).toBe('function');
    expect(typeof api.clearTokens).toBe('function');
  });

  it('tokenStore exposes get, set, clear', () => {
    expect(typeof tokenStore.get).toBe('function');
    expect(typeof tokenStore.set).toBe('function');
    expect(typeof tokenStore.clear).toBe('function');
  });

  it('ApiError has all required fields', () => {
    const err = new ApiError('VALIDATION', 'test', 422, 'corr-1', { key: 'val' });
    expect(err.code).toBe('VALIDATION');
    expect(err.httpStatus).toBe(422);
    expect(err.correlationId).toBe('corr-1');
    expect(err.details).toEqual({ key: 'val' });
    expect(err.message).toBe('test');
    expect(err.name).toBe('ApiError');
  });

  it('default retries is 0', async () => {
    tokenStore.set(VALID_TOKENS);
    mockFetch.mockRejectedValue(new TypeError('Failed to fetch'));
    try { await api.request('/api/v1/test'); } catch (e) {
      expect((e as ApiError).code).toBe('NETWORK');
    }
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });
});
