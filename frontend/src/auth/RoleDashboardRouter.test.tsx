import { render, screen, waitFor } from '@testing-library/react';
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
function LoginHarness() {
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
          <LoginHarness />
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
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes superadmin (with facility) to /dashboard', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['superadmin'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes org_admin to /dashboard', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: null, facilityName: null, roles: ['org_admin'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes hospital_admin to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['hospital_admin'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes doctor to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['doctor'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes nurse to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['nurse'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes pharmacist to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['pharmacist'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes lab_technician to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['lab_technician'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes radiologist to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['radiologist'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes billing_clerk to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['billing_clerk'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes receptionist to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['receptionist'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes org_finance to /dashboard', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: null, facilityName: null, roles: ['org_finance'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes unknown role to /dashboard (fallback)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['support_agent'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes doctor with no facility to /dashboard', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: null, facilityName: null, roles: ['doctor'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });

  it('routes multi-role user to /dashboard (Model A)', async () => {
    renderRouter([
      { organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Hospital A', roles: ['doctor', 'pharmacist'] },
    ]);
    await waitFor(() => {
      expect(screen.getByTestId('location')).toHaveTextContent('/dashboard');
    });
  });
});
