import { act, render, screen } from '@testing-library/react';
import { useEffect } from 'react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { describe, expect, it, beforeEach } from 'vitest';
import { AuthProvider, useAuth } from './AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { RoleDashboardRouter } from './RoleDashboardRouter';
import { jsonOk, stubFetch } from '../test/helpers';

/** Capture where the router navigated to. */
function LocationSpy() {
  const loc = useLocation();
  return <span data-testid="location">{loc.pathname}</span>;
}

/** Trigger login and render the router. */
function LoginHarness({ assignments }: { assignments: unknown[] }) {
  const { login } = useAuth();
  useEffect(() => {
    void login('a@b.test', 'secret');
  }, [login]);
  return (
    <>
      <RoleDashboardRouter />
      <LocationSpy />
    </>
  );
}

function renderRouter(assignments: unknown[]) {
  stubFetch(
    jsonOk({
      accessToken: 'at',
      refreshToken: 'rt',
      tokenType: 'Bearer',
      expiresIn: 3600,
      refreshExpiresIn: 604800,
      user: { id: 'u1', email: 'a@b.test', status: 'active' },
      assignments,
    }),
  );
  return render(
    <MemoryRouter initialEntries={['/']}>
      <AuthProvider>
        <TenantProvider>
          <LoginHarness assignments={assignments} />
        </TenantProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('RoleDashboardRouter', () => {
  beforeEach(() => {
    sessionStorage.clear();
  });

  it('routes superadmin (no facility) to /dashboard', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: null, facilityName: null, roles: ['superadmin'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/dashboard');
  });

  it('routes superadmin (with facility) to /dashboard', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['superadmin'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/dashboard');
  });

  it('routes org_admin to /dashboard', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: null, facilityName: null, roles: ['org_admin'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/dashboard');
  });

  it('routes hospital_admin to /hospital', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['hospital_admin'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/hospital');
  });

  it('routes doctor to /clinical', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['doctor'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/clinical');
  });

  it('routes nurse to /clinical', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['nurse'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/clinical');
  });

  it('routes pharmacist to /pharmacy', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['pharmacist'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/pharmacy');
  });

  it('routes lab_technician to /laboratory', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['lab_technician'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/laboratory');
  });

  it('routes radiologist to /radiology', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['radiologist'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/radiology');
  });

  it('routes billing_clerk to /finance', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['billing_clerk'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/finance');
  });

  it('routes receptionist to /hospital', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['receptionist'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/hospital');
  });

  it('routes org_finance to /dashboard', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: null, facilityName: null, roles: ['org_finance'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/dashboard');
  });

  it('falls back to /dashboard for unknown role with facility', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['support_agent'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/dashboard');
  });

  it('falls back to /dashboard for doctor with no facility', async () => {
    // doctor's path is /clinical which requires a facility, so falls back.
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: null, facilityName: null, roles: ['doctor'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/dashboard');
  });

  it('respects role priority: doctor+pharmacist routes to clinical (first match)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['doctor', 'pharmacist'] },
    ]);
    expect(await screen.findByTestId('location')).toHaveTextContent('/clinical');
  });
});
