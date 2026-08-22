import type { AuthTokens } from './client';

/**
 * Portal-specific token storage that uses different localStorage keys
 * from the staff auth client. This prevents the AuthProvider's refresh
 * flow from clearing portal tokens.
 */
const PORTAL_ACCESS_KEY = 'swasthya.portal.accessToken';
const PORTAL_REFRESH_KEY = 'swasthya.portal.refreshToken';

export const portalTokenStore = {
  get(): AuthTokens | null {
    const access = sessionStorage.getItem(PORTAL_ACCESS_KEY);
    const refresh = localStorage.getItem(PORTAL_REFRESH_KEY);
    if (!access || !refresh) return null;
    return {
      accessToken: access,
      refreshToken: refresh,
      expiresIn: 3600,
      refreshExpiresIn: 604800,
    };
  },
  set(tokens: AuthTokens): void {
    sessionStorage.setItem(PORTAL_ACCESS_KEY, tokens.accessToken);
    localStorage.setItem(PORTAL_REFRESH_KEY, tokens.refreshToken);
  },
  clear(): void {
    sessionStorage.removeItem(PORTAL_ACCESS_KEY);
    localStorage.removeItem(PORTAL_REFRESH_KEY);
  },
};

/**
 * Create a portal-specific fetch wrapper that reads tokens from
 * the portal token store instead of the staff token store.
 */
export function portalFetch(path: string, init?: RequestInit): Promise<Response> {
  const tokens = portalTokenStore.get();
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...((init?.headers as Record<string, string>) ?? {}),
  };
  if (tokens) {
    headers['Authorization'] = `Bearer ${tokens.accessToken}`;
  }
  return fetch(path, { ...init, headers });
}

/**
 * Convenience: make a JSON request via the portal API and
 * return the unwrapped data envelope.
 */
export async function portalRequest<T>(path: string, options?: { method?: string; body?: unknown }): Promise<T> {
  const res = await portalFetch(path, {
    method: options?.method ?? 'GET',
    body: options?.body !== undefined ? JSON.stringify(options.body) : undefined,
  });
  const json = (await res.json()) as { data?: T; error?: { message?: string } };
  if (!res.ok || !json.data) {
    throw new Error(json.error?.message ?? `Portal API error ${res.status}`);
  }
  return json.data;
}
