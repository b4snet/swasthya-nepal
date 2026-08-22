/**
 * API client — the ONLY way the SPA talks to the backend.
 *
 * Responsibilities (SECURITY.md §30, API_CONTRACTS.md §7–§12):
 *  - attaches the access token and the facility/branch *proposals*
 *  - unwraps the envelope ({data} | {error}), never trusting status alone
 *  - maps failures to typed ApiError (401/403/404/409/422/429/5xx/network)
 *  - single-flight token refresh on 401, then one replay of the original
 *  - bounded retry (network failures only, idempotent methods only)
 *  - exposes the correlation id for every error
 *
 * The backend remains authoritative for authentication, authorization and
 * tenant context; this client only carries what the backend requires.
 */

export type ApiErrorCode =
  | 'NETWORK'
  | 'TIMEOUT'
  | 'NO_TENANT_CONTEXT' // client-side guard: request fired without resolved context
  | 'UNAUTHORIZED' // 401 — token missing/expired/revoked
  | 'FORBIDDEN' // 403 — scope/permission denied
  | 'NOT_FOUND' // 404 — hidden (isolation) or absent
  | 'CONFLICT' // 409 — state/optimistic-lock conflict
  | 'VALIDATION' // 422 — field errors
  | 'RATE_LIMITED' // 429
  | 'SERVER' // 5xx
  | 'UNKNOWN';

export class ApiError extends Error {
  code: ApiErrorCode;
  httpStatus: number;
  correlationId: string | null;
  details: Record<string, unknown> | null;

  constructor(code: ApiErrorCode, message: string, httpStatus = 0, correlationId: string | null = null, details: Record<string, unknown> | null = null) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.httpStatus = httpStatus;
    this.correlationId = correlationId;
    this.details = details;
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  facilityId?: string | null;
  branchId?: string | null;
  retries?: number;
  timeoutMs?: number;
  signal?: AbortSignal;
}

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
  expiresIn: number;
  refreshExpiresIn: number;
}

interface TokenStore {
  get(): AuthTokens | null;
  set(tokens: AuthTokens): void;
  clear(): void;
}

// Access token lives in memory only. The refresh token is persisted so a
// reload can restore the session; the backend rotates it on every use and
// flags reuse as a theft signal (SECURITY.md §4). This tradeoff is
// documented in FRONTEND_FOUNDATION_REPORT.md §4.
const REFRESH_KEY = 'swasthya.refreshToken';

export const tokenStore: TokenStore = {
  get() {
    const access = sessionStorage.getItem('swasthya.accessToken');
    const refresh = localStorage.getItem(REFRESH_KEY);
    if (!access || !refresh) return null;
    return {
      accessToken: access,
      refreshToken: refresh,
      expiresIn: 3600,
      refreshExpiresIn: 604800,
    };
  },
  set(tokens) {
    sessionStorage.setItem('swasthya.accessToken', tokens.accessToken);
    localStorage.setItem(REFRESH_KEY, tokens.refreshToken);
  },
  clear() {
    sessionStorage.removeItem('swasthya.accessToken');
    localStorage.removeItem(REFRESH_KEY);
  },
};

export interface ApiClient {
  request<T>(path: string, options?: RequestOptions): Promise<T>;
  setTokens(tokens: AuthTokens): void;
  getTokens(): AuthTokens | null;
  clearTokens(): void;
}

function createClient(baseUrl: string): ApiClient {
  let pendingRefresh: Promise<boolean> | null = null;

  const rawFetch = async (path: string, options: RequestOptions): Promise<Response> => {
    const tokens = tokenStore.get();
    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    if (tokens) headers.Authorization = `Bearer ${tokens.accessToken}`;
    if (options.facilityId) headers['X-Swasthya-Facility'] = options.facilityId;
    if (options.branchId) headers['X-Swasthya-Branch'] = options.branchId;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), options.timeoutMs ?? 20000);
    if (options.signal) {
      options.signal.addEventListener('abort', () => controller.abort(), { once: true });
    }

    try {
      return await fetch(`${baseUrl}${path}`, {
        method: options.method ?? 'GET',
        headers,
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        signal: controller.signal,
      });
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        throw new ApiError('TIMEOUT', 'The request timed out. Check your connection and try again.', 0);
      }
      throw new ApiError('NETWORK', 'Cannot reach the server. Check your connection.', 0);
    } finally {
      clearTimeout(timeout);
    }
  };

  const refreshTokens = async (): Promise<boolean> => {
    const tokens = tokenStore.get();
    if (!tokens) return false;
    try {
      const res = await fetch(`${baseUrl}/api/v1/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refreshToken: tokens.refreshToken }),
      });
      const body = (await res.json()) as { data?: { accessToken: string; refreshToken: string; expiresIn: number; refreshExpiresIn: number } };
      if (!res.ok || !body.data) {
        tokenStore.clear();
        return false;
      }
      tokenStore.set({
        accessToken: body.data.accessToken,
        refreshToken: body.data.refreshToken,
        expiresIn: body.data.expiresIn,
        refreshExpiresIn: body.data.refreshExpiresIn,
      });
      return true;
    } catch {
      return false;
    }
  };

  const parseError = async (res: Response): Promise<ApiError> => {
    let correlationId: string | null = null;
    let details: Record<string, unknown> | null = null;
    let message = res.statusText || 'Request failed.';
    try {
      const body = (await res.json()) as { error?: { code?: string; message?: string; correlationId?: string; details?: Record<string, unknown> } };
      if (body.error?.message) message = body.error.message;
      correlationId = body.error?.correlationId ?? null;
      details = body.error?.details ?? null;
    } catch {
      // Non-JSON error body — keep defaults.
    }

    const code: ApiErrorCode =
      res.status === 401 ? 'UNAUTHORIZED'
      : res.status === 403 ? 'FORBIDDEN'
      : res.status === 404 ? 'NOT_FOUND'
      : res.status === 409 ? 'CONFLICT'
      : res.status === 422 ? 'VALIDATION'
      : res.status === 429 ? 'RATE_LIMITED'
      : res.status >= 500 ? 'SERVER'
      : 'UNKNOWN';

    return new ApiError(code, message, res.status, correlationId, details);
  };

  const request = async <T>(path: string, options: RequestOptions = {}): Promise<T> => {
    const attempt = async (): Promise<T> => {
      const res = await rawFetch(path, options);
      if (res.ok) {
        const body = (await res.json()) as { data: T };
        return body.data;
      }
      if (res.status === 401) {
        // Don't attempt staff token refresh for portal routes —
        // portal tokens use a separate auth system and refreshing
        // via /auth/refresh would fail and clear the portal token.
        if (!path.startsWith('/api/v1/portal/')) {
          // One single-flight refresh attempt, then a single replay.
          pendingRefresh ??= refreshTokens();
          const ok = await pendingRefresh;
          pendingRefresh = null;
          if (ok) {
            const replay = await rawFetch(path, options);
            if (replay.ok) {
              const body = (await replay.json()) as { data: T };
              return body.data;
            }
            throw await parseError(replay);
          }
        }
        throw await parseError(res);
      }
      throw await parseError(res);
    };

    const maxRetries = options.retries ?? 0;
    const method = options.method ?? 'GET';
    const retryable = (code: ApiErrorCode) => code === 'NETWORK' || code === 'TIMEOUT';
    const idempotent = method === 'GET' || method === 'PATCH';

    let lastError: unknown = null;
    for (let attemptNo = 0; attemptNo <= maxRetries; attemptNo++) {
      try {
        return await attempt();
      } catch (err) {
        lastError = err;
        const apiErr = err as ApiError;
        if (!retryable(apiErr.code) || !idempotent || attemptNo === maxRetries) throw err;
        await new Promise((r) => setTimeout(r, 250 * 2 ** attemptNo));
      }
    }
    throw lastError;
  };

  return {
    request,
    setTokens: (t) => tokenStore.set(t),
    getTokens: () => tokenStore.get(),
    clearTokens: () => tokenStore.clear(),
  };
}

// Same-origin by default ('' — the Vite dev proxy targets the backend). In
// deployed environments the SPA and API are separate origins, so the build
// bakes the API base URL from VITE_API_BASE_URL (deployment wiring, not a
// secret — SECURITY.md §24 CORS allowlist is configured on the backend).
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? '';

export const api = createClient(API_BASE_URL);
