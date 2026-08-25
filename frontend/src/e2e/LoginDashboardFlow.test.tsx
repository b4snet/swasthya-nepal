import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { describe, it, expect, beforeEach } from 'vitest';
import { AuthProvider, useAuth } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { LoginPage } from '../pages/LoginPage';
import { RoleDashboardRouter } from '../auth/RoleDashboardRouter';
import { I18nProvider } from '../i18n/I18nProvider';
import { jsonOk, jsonError, stubFetch } from '../test/helpers';
import { useEffect } from 'react';

const envResp = jsonOk({ data: { environment: 'testing', version: '1.0' } });

function LocationSpy() {
  const loc = useLocation();
  return <span data-testid="location">{loc.pathname}</span>;
}

function LoginHarness() {
  const { login } = useAuth();
  useEffect(() => { void login('a@b.test', 'secret'); }, [login]);
  return (<><RoleDashboardRouter /><LocationSpy /></>);
}

function renderLogin() {
  return render(
    <MemoryRouter initialEntries={['/login']}>
      <I18nProvider><AuthProvider><TenantProvider><LoginPage /></TenantProvider></AuthProvider></I18nProvider>
    </MemoryRouter>,
  );
}

function renderRouter(assignments: unknown[]) {
  stubFetch(
    jsonOk({ accessToken: 'at', refreshToken: 'rt', tokenType: 'Bearer', expiresIn: 3600, refreshExpiresIn: 604800,
      user: { id: 'u1', email: 'a@b.test', status: 'active' }, assignments }),
  );
  return render(
    <MemoryRouter initialEntries={['/']}>
      <I18nProvider><AuthProvider><TenantProvider><LoginHarness /></TenantProvider></AuthProvider></I18nProvider>
    </MemoryRouter>,
  );
}

describe('E2E: Authentication + Role Routing', () => {
  beforeEach(() => { localStorage.clear(); sessionStorage.clear(); });

  it('renders login form with email, password, and submit', () => {
    stubFetch(envResp);
    renderLogin();
    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument();
  });

  it('shows alert on invalid credentials (401)', async () => {
    stubFetch(envResp, jsonError(401, 'INVALID_CREDENTIALS', 'Invalid credentials.'));
    renderLogin();
    const user = (await import('@testing-library/user-event')).default.setup();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.type(screen.getByLabelText(/password/i), 'wrong');
    await user.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/sign-in failed/i);
  });

  it('shows rate-limit message on 429', async () => {
    stubFetch(envResp, jsonError(429, 'RATE_LIMITED', 'Too many attempts'));
    renderLogin();
    const user = (await import('@testing-library/user-event')).default.setup();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.type(screen.getByLabelText(/password/i), 'pass');
    await user.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/too many/i);
  });

  it('hospital_admin routed to /dashboard', async () => {
    renderRouter([{ organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'H', roles: ['hospital_admin'] }]);
    await waitFor(() => { expect(screen.getByTestId('location')).toHaveTextContent('/dashboard'); });
  });

  it('doctor routed to /dashboard', async () => {
    renderRouter([{ organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'H', roles: ['doctor'] }]);
    await waitFor(() => { expect(screen.getByTestId('location')).toHaveTextContent('/dashboard'); });
  });

  it('pharmacist routed to /dashboard', async () => {
    renderRouter([{ organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'H', roles: ['pharmacist'] }]);
    await waitFor(() => { expect(screen.getByTestId('location')).toHaveTextContent('/dashboard'); });
  });

  it('nurse routed to /dashboard', async () => {
    renderRouter([{ organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'H', roles: ['nurse'] }]);
    await waitFor(() => { expect(screen.getByTestId('location')).toHaveTextContent('/dashboard'); });
  });
});
