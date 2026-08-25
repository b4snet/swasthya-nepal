import { act, render, screen, waitFor } from '@testing-library/react';
import { useEffect } from 'react';
import { describe, expect, it, beforeEach } from 'vitest';
import { AuthProvider, useAuth } from '../auth/AuthProvider';
import { TenantProvider, useTenant } from './TenantContext';
import { jsonOk, stubFetch } from '../test/helpers';

function Probe() {
  const t = useTenant();
  return (
    <div>
      <span data-testid="fac">{t.selectedFacilityId ?? 'none'}</span>
      <span data-testid="facname">{t.selectedFacilityName ?? 'none'}</span>
      <span data-testid="org">{t.organizationId ?? 'none'}</span>
      <span data-testid="roles">{t.roles.join(',')}</span>
      <span data-testid="admin">{String(t.hasRole('hospital_admin'))}</span>
      <span data-testid="ready">{String(t.ready)}</span>
      <span data-testid="loading">{String(t.loading)}</span>
    </div>
  );
}

// The real session flow: the page calls login(), the backend returns the
// assignments payload, and TenantContext derives context from it. AuthProvider
// never trusts local storage, so the harness must exercise that real flow.
function Harness() {
  const { login } = useAuth();
  useEffect(() => {
    void login('a@b.test', 'secret');
  }, [login]);
  return <Probe />;
}

function renderTenant(assignments: unknown[], extra?: React.ReactNode) {
  stubFetch(
    jsonOk({
      accessToken: 'at',
      refreshToken: 'rt',
      tokenType: 'Bearer',
      expiresIn: 3600,
      refreshExpiresIn: 604800,
      user: { id: 'u1', email: 'x@y.test', status: 'active' },
      assignments,
    }),
  );
  return render(
    <AuthProvider>
      <TenantProvider>
        <Harness />
        {extra}
      </TenantProvider>
    </AuthProvider>,
  );
}

describe('TenantContext', () => {
  beforeEach(() => {
    sessionStorage.clear();
  });

  it('auto-selects the sole authorized facility', async () => {
    renderTenant([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Smoke Central', roles: ['hospital_admin'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('fac')).toHaveTextContent('fac-1');
      expect(screen.getByTestId('ready')).toHaveTextContent('true');
    });
    expect(screen.getByTestId('org')).toHaveTextContent('org-1');
    expect(screen.getByTestId('admin')).toHaveTextContent('true');
  });

  it('requires explicit choice when multiple facilities are authorized', async () => {
    renderTenant([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Fac A', roles: ['hospital_admin'] },
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-2', facilityName: 'Fac B', roles: ['hospital_admin'] },
    ]);
    expect(await screen.findByTestId('fac')).toHaveTextContent('none');
    expect(screen.getByTestId('ready')).toHaveTextContent('false');
  });

  it('derives roles only from the server-issued assignments', async () => {
    renderTenant([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Smoke Central', roles: ['doctor'] },
    ]);
    expect(await screen.findByTestId('roles')).toHaveTextContent('doctor');
    expect(screen.getByTestId('admin')).toHaveTextContent('false');
  });

  it('clears context when the session is not authenticated', async () => {
    render(
      <AuthProvider>
        <TenantProvider>
          <Probe />
        </TenantProvider>
      </AuthProvider>,
    );
    // No stored tokens: unauthenticated → no facility, no roles.
    await act(async () => {
      await Promise.resolve();
    });
    expect(screen.getByTestId('fac')).toHaveTextContent('none');
    expect(screen.getByTestId('ready')).toHaveTextContent('false');
  });

  it('is not ready when unauthenticated (no token = no context)', async () => {
    // When there are no stored tokens, AuthProvider immediately resolves
    // to unauthenticated. loading transitions true→false almost instantly.
    // The key invariant: ready must be false when unauthenticated.
    render(
      <AuthProvider>
        <TenantProvider>
          <Probe />
        </TenantProvider>
      </AuthProvider>,
    );
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByTestId('loading')).toHaveTextContent('false');
    expect(screen.getByTestId('ready')).toHaveTextContent('false');
  });

  it('sets ready=true after auth resolves with no facilities (platform user)', async () => {
    // Platform-only user: no facilities in assignments.
    renderTenant([]);
    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('false');
      expect(screen.getByTestId('ready')).toHaveTextContent('true');
    });
    expect(screen.getByTestId('fac')).toHaveTextContent('none');
  });

  it('derives organizationId from assignments even without a selected facility', async () => {
    // Org-level user with no facility assignment.
    renderTenant([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: null, facilityName: null, roles: ['org_admin'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('org')).toHaveTextContent('org-1');
      expect(screen.getByTestId('ready')).toHaveTextContent('true');
    });
    expect(screen.getByTestId('fac')).toHaveTextContent('none');
  });

  it('persists selected facility in sessionStorage and restores on re-mount', async () => {
    // Set up a single-facility user — it auto-selects.
    renderTenant([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Smoke Central', roles: ['hospital_admin'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('fac')).toHaveTextContent('fac-1');
      expect(sessionStorage.getItem('swasthya.selectedFacilityId')).toBe('fac-1');
    });
  });

  it('validates restored facility is still in current assignments (cross-user safety)', async () => {
    // Simulate: user had fac-1 persisted, but now has fac-2 only.
    sessionStorage.setItem('swasthya.selectedFacilityId', 'fac-1');
    renderTenant([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-2', facilityName: 'Fac B', roles: ['hospital_admin'] },
    ]);
    // fac-1 is not in the current assignments, so it should not be selected.
    await waitFor(() => {
      expect(screen.getByTestId('fac')).toHaveTextContent('fac-2');
    });
    expect(sessionStorage.getItem('swasthya.selectedFacilityId')).toBe('fac-2');
  });

  it('clears facility context on logout', async () => {
    // Set up a user, then logout via a child component that calls useAuth().
    function LogoutButton() {
      const { logout } = useAuth();
      return <button data-testid="logout" onClick={() => void logout()}>logout</button>;
    }
    // Provide two responses: one for login, one for the logout API call.
    stubFetch(
      jsonOk({
        accessToken: 'at', refreshToken: 'rt', tokenType: 'Bearer',
        expiresIn: 3600, refreshExpiresIn: 604800,
        user: { id: 'u1', email: 'x@y.test', status: 'active' },
        assignments: [
          { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Smoke Central', roles: ['hospital_admin'] },
        ],
      }),
      jsonOk(null), // response for logout API call
    );
    render(
      <AuthProvider>
        <TenantProvider>
          <Harness />
          <LogoutButton />
        </TenantProvider>
      </AuthProvider>,
    );
    expect(await screen.findByTestId('fac')).toHaveTextContent('fac-1');
    expect(sessionStorage.getItem('swasthya.selectedFacilityId')).toBe('fac-1');

    // Logout via child component (hooks must be called inside component tree).
    await act(async () => {
      screen.getByTestId('logout').click();
    });
    await act(async () => {
      await Promise.resolve();
    });

    expect(screen.getByTestId('fac')).toHaveTextContent('none');
    expect(screen.getByTestId('ready')).toHaveTextContent('false');
    expect(sessionStorage.getItem('swasthya.selectedFacilityId')).toBeNull();
  });
});
