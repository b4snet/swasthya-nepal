import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { authApi } from '../api/endpoints';
import { api } from '../api/client';
import type { Assignment, SessionUser } from '../api/types';

export type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated';

interface AuthState {
  status: AuthStatus;
  user: SessionUser | null;
  assignments: Assignment[];
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('loading');
  const [user, setUser] = useState<SessionUser | null>(null);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
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
      setStatus('unauthenticated');
    }
  }, []);

  useEffect(() => {
    if (restoring.current) return;
    restoring.current = true;
    const tokens = api.getTokens();
    if (!tokens) {
      setStatus('unauthenticated');
      return;
    }
    // Session restoration: exchange the refresh token for a fresh session.
    // The refresh token rotates; the backend flags reuse as theft (SECURITY.md §4).
    authApi
      .refresh(tokens.refreshToken)
      .then((res) => applySession(res))
      .catch(() => {
        api.clearTokens();
        setStatus('unauthenticated');
      });
  }, [applySession]);

  const value = useMemo(() => ({ status, user, assignments, login, logout }), [status, user, assignments, login, logout]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
