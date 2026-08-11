import { act, render, screen } from '@testing-library/react';
import { useEffect } from 'react';
import { describe, expect, it } from 'vitest';
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

function renderTenant(assignments: unknown[]) {
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
      </TenantProvider>
    </AuthProvider>,
  );
}

describe('TenantContext', () => {
  it('auto-selects the sole authorized facility', async () => {
    renderTenant([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Smoke Central', roles: ['hospital_admin'] },
    ]);
    expect(await screen.findByTestId('fac')).toHaveTextContent('fac-1');
    expect(screen.getByTestId('org')).toHaveTextContent('org-1');
    expect(screen.getByTestId('admin')).toHaveTextContent('true');
  });

  it('requires explicit choice when multiple facilities are authorized', async () => {
    renderTenant([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Fac A', roles: ['hospital_admin'] },
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-2', facilityName: 'Fac B', roles: ['hospital_admin'] },
    ]);
    expect(await screen.findByTestId('fac')).toHaveTextContent('none');
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
  });
});
