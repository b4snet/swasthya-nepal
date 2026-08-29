import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { authApi } from '../api/endpoints';
import { api } from '../api/client';
import type { Assignment, SessionUser } from '../api/types';

export type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated';

/**
 * Why the user is unauthenticated. The login page renders a user-facing
 * banner when the reason is 'expired' so the user understands why they
 * were redirected.
 */
export type SessionExpiredReason = 'expired' | 'revoked' | null;

interface AuthState {
  status: AuthStatus;
  user: SessionUser | null;
  assignments: Assignment[];
  /** Non-null when the user was redirected to /login because their session ended. */
  sessionExpiredReason: SessionExpiredReason;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  /** Clear the session-expired banner after the user has seen it. */
  clearExpiredReason: () => void;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('loading');
  const [user, setUser] = useState<SessionUser | null>(null);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [sessionExpiredReason, setSessionExpiredReason] = useState<SessionExpiredReason>(null);
  const restoring = useRef(false);

  const applySession = useCallback((data: { user: SessionUser; assignments: Assignment[]; accessToken: string; refreshToken: string; expiresIn: number; refreshExpiresIn: number }) => {
    api.setTokens({
      accessToken: data.accessToken,
      refreshToken: data.refreshToken,
      expiresIn: data.expiresIn,
      refreshExpiresIn: data.refreshExpiresIn,
    });
    setUser(data.user);
    setAssignments(data.assignments);
    setSessionExpiredReason(null);
    setStatus('authenticated');
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      const res = await authApi.login(email, password);
      applySession(res);
    },
    [applySession],
  );

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } catch {
      // Local teardown regardless of network state.
    } finally {
      api.clearTokens();
      setUser(null);
      setAssignments([]);
      setSessionExpiredReason(null);
      setStatus('unauthenticated');
    }
  }, []);

  const clearExpiredReason = useCallback(() => {
    setSessionExpiredReason(null);
  }, []);

  // Session restoration on mount
  useEffect(() => {
    if (restoring.current) return;
    restoring.current = true;
    const tokens = api.getTokens();
    if (!tokens) {
      setStatus('unauthenticated');
      return;
    }
    authApi
      .refresh(tokens.refreshToken)
      .then((res) => applySession(res))
      .catch(() => {
        api.clearTokens();
        setSessionExpiredReason('expired');
        setStatus('unauthenticated');
      });
  }, [applySession]);

  // Proactive token refresh — refresh 5 minutes before access token expires
  useEffect(() => {
    if (status !== 'authenticated') return;
    const tokens = api.getTokens();
    if (!tokens) return;

    // Refresh 5 minutes before expiry, or immediately if already close
    const expiresAt = Date.now() + tokens.expiresIn * 1000;
    const refreshIn = Math.max((expiresAt - Date.now()) - 5 * 60 * 1000, 0);

    const timer = setTimeout(() => {
      authApi
        .refresh(tokens.refreshToken)
        .then((res) => applySession(res))
        .catch(() => {
          api.clearTokens();
          setSessionExpiredReason('expired');
          setStatus('unauthenticated');
        });
    }, refreshIn);

    return () => clearTimeout(timer);
  }, [status, applySession]);

  const value = useMemo(() => ({ status, user, assignments, sessionExpiredReason, login, logout, clearExpiredReason }), [status, user, assignments, sessionExpiredReason, login, logout, clearExpiredReason]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
