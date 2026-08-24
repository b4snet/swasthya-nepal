import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { jsonOk, stubFetch } from '../test/helpers';
import { AppShell } from './AppShell';

function sessionPayload(roles: string[]) {
  return jsonOk({
    accessToken: 'at-test',
    tokenType: 'Bearer',
    expiresIn: 3600,
    refreshToken: 'rt-test',
    refreshExpiresIn: 604800,
    user: { id: 'u-1', email: 'user@swasthya.test', status: 'active' },
    assignments: [
      { organizationId: 'org-1', organizationCode: 'SMOKE', facilityId: 'fac-1', facilityName: 'Smoke Central', roles },
    ],
  });
}

/** Real session-restore path: seeded tokens make AuthProvider call /auth/refresh. */
async function renderShell(roles: string[]) {
  localStorage.setItem('swasthya.refreshToken', 'rt-test');
  sessionStorage.setItem('swasthya.accessToken', 'at-test');
  stubFetch(sessionPayload(roles));
  render(
    <MemoryRouter>
      <AuthProvider>
        <TenantProvider>
          <AppShell />
        </TenantProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
  // Wait for the app shell to render
  await waitFor(() => {
    expect(screen.queryByTestId('user-menu-trigger')).not.toBeNull();
  });
}

const countSidebar = (key: string) => screen.queryAllByTestId(`sidebar-${key}`).length;

describe('AppShell sidebar navigation', () => {
  it('shows sidebar with top-level modules', async () => {
    await renderShell(['superadmin']);
    // Core modules should always be visible
    expect(countSidebar('hospital')).toBeGreaterThan(0);
    expect(countSidebar('clinical')).toBeGreaterThan(0);
    expect(countSidebar('pharmacy')).toBeGreaterThan(0);
    expect(countSidebar('finance')).toBeGreaterThan(0);
    // Dashboard always present
    expect(countSidebar('dashboard')).toBeGreaterThan(0);
  });

  it('hides admin module from non-admin roles', async () => {
    await renderShell(['doctor']);
    expect(countSidebar('admin')).toBe(0);
  });

  it('hides hospital module from doctor role (admin-only module)', async () => {
    await renderShell(['doctor']);
    expect(countSidebar('hospital')).toBe(0);
  });

  it('hides finance module from doctor role', async () => {
    await renderShell(['doctor']);
    expect(countSidebar('finance')).toBe(0);
  });

  it('shows admin module to admin roles', async () => {
    await renderShell(['superadmin']);
    expect(countSidebar('admin')).toBeGreaterThan(0);
  });

  it('shows children below clinical module for clinical roles', async () => {
    await renderShell(['receptionist']);
    // Click on clinical module to expand its children
    const clinicalBtn = screen.getByTestId('sidebar-clinical');
    clinicalBtn.click();
    // Wait for children to appear with Queue
    await waitFor(() => {
      expect(screen.queryByTestId('sidebar-clin-queue')).not.toBeNull();
    });
  });

  it('shows finance module to billing clerk', async () => {
    await renderShell(['billing_clerk']);
    expect(countSidebar('finance')).toBeGreaterThan(0);
  });

  it('shows billing child under finance for billing clerk', async () => {
    await renderShell(['billing_clerk']);
    const financeBtn = screen.getByTestId('sidebar-finance');
    financeBtn.click();
    await waitFor(() => {
      expect(screen.queryByTestId('sidebar-fin-billing')).not.toBeNull();
    });
  });

  it('dashboard is always the first sidebar item', async () => {
    await renderShell(['doctor']);
    const dashboardBtn = screen.getByTestId('sidebar-dashboard');
    // Dashboard should be before all other sidebar items
    const allSidebarItems = screen.getAllByTestId(/^sidebar-/);
    expect(allSidebarItems[0]).toBe(dashboardBtn);
  });
});
