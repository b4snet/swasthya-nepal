import { act, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, beforeEach } from 'vitest';
import { AuthProvider } from './AuthProvider';
import { LoginPage } from '../pages/LoginPage';
import { I18nProvider } from '../i18n/I18nProvider';
import { jsonOk, jsonError, stubFetch } from '../test/helpers';

function renderLogin() {
  return render(
    <MemoryRouter>
      <I18nProvider>
        <AuthProvider>
          <LoginPage />
        </AuthProvider>
      </I18nProvider>
    </MemoryRouter>,
  );
}

describe('Session expiry UX', () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  // LoginPage fetches /health/env and AuthProvider refreshes the cookie on
  // mount. Child effects (fetchEnvironment) run before the AuthProvider's
  // refresh effect, so the first stub response serves env and the second the
  // refresh call.
  const envResponse = jsonOk({ environment: 'testing', isProduction: false });

  it('shows expired session banner when the cookie-based refresh fails', async () => {
    // AuthProvider ALWAYS attempts a cookie-based refresh on mount (the
    // refresh token lives only in the httpOnly cookie, never JS storage).
    // Stub the refresh endpoint to fail (401).
    stubFetch(envResponse, jsonError(401, 'TOKEN_EXPIRED', 'Refresh token expired.'));

    renderLogin();

    // Wait for the refresh attempt to complete and the banner to appear.
    expect(await screen.findByTestId('session-expired-banner')).toHaveTextContent(/session has expired/i);
  });

  it('clears the expired banner after login attempt', async () => {
    stubFetch(
      envResponse,
      jsonError(401, 'TOKEN_EXPIRED', 'Refresh token expired.'), // refresh fails
    );

    renderLogin();
    await screen.findByTestId('session-expired-banner');

    // Now stub the login endpoint to succeed.
    stubFetch(jsonOk({
      accessToken: 'new-at',
      tokenType: 'Bearer',
      expiresIn: 3600,
      refreshToken: 'new-rt',
      refreshExpiresIn: 604800,
      user: { id: 'u1', email: 'a@b.test', status: 'active' },
      assignments: [{ organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Fac', roles: ['hospital_admin'] }],
    }));

    const user = (await import('@testing-library/user-event')).default.setup();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.type(screen.getByLabelText(/password/i), 'secret');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => {
      expect(screen.queryByTestId('session-expired-banner')).not.toBeInTheDocument();
    });
  });

  it('recovers a valid session from the cookie on reload (no expired banner)', async () => {
    // On reload with a valid cookie, refresh succeeds and restores the
    // session — no expired banner, authenticated state.
    stubFetch(
      envResponse,
      jsonOk({
        accessToken: 'restored-at',
        tokenType: 'Bearer',
        expiresIn: 3600,
        refreshToken: 'restored-rt',
        refreshExpiresIn: 604800,
        user: { id: 'u1', email: 'a@b.test', status: 'active' },
        assignments: [{ organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Fac', roles: ['hospital_admin'] }],
      }),
    );

    renderLogin();

    await waitFor(() => {
      expect(screen.queryByTestId('session-expired-banner')).not.toBeInTheDocument();
    });
  });
});
