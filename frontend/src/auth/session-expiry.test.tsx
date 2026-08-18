import { act, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
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
  it('shows expired session banner when refresh token fails', async () => {
    // Seed a refresh token so AuthProvider attempts refresh on mount.
    localStorage.setItem('swasthya.refreshToken', 'expired-rt');
    sessionStorage.setItem('swasthya.accessToken', 'expired-at');
    // Stub the refresh endpoint to fail (401).
    stubFetch(jsonError(401, 'TOKEN_EXPIRED', 'Refresh token expired.'));

    renderLogin();

    // Wait for the refresh attempt to complete and the banner to appear.
    expect(await screen.findByTestId('session-expired-banner')).toHaveTextContent(/session has expired/i);
  });

  it('clears the expired banner after login attempt', async () => {
    localStorage.setItem('swasthya.refreshToken', 'expired-rt');
    sessionStorage.setItem('swasthya.accessToken', 'expired-at');
    stubFetch(
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

  it('does not show expired banner when there were no stored tokens', async () => {
    localStorage.removeItem('swasthya.refreshToken');
    sessionStorage.removeItem('swasthya.accessToken');
    renderLogin();
    await act(async () => { await Promise.resolve(); });
    expect(screen.queryByTestId('session-expired-banner')).not.toBeInTheDocument();
  });
});
