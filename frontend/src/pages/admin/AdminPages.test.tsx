import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../../auth/AuthProvider';
import { TenantProvider } from '../../context/TenantContext';
import { I18nProvider } from '../../i18n/I18nProvider';
import { jsonOk } from '../../test/helpers';
import { AdminUsersPage } from './AdminUsersPage';
import { AdminRolesPage } from './AdminRolesPage';
import { AdminDepartmentsPage } from './AdminDepartmentsPage';
import { AdminServicesPage } from './AdminServicesPage';
import { AdminMedicationsPage } from './AdminMedicationsPage';
import { AdminSettingsPage } from './AdminSettingsPage';
import { ADMIN_ROLES } from '../../auth/roles';

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

function sessionPayload(roles: string[]) {
  return jsonOk({
    accessToken: 'at-admin',
    tokenType: 'Bearer',
    expiresIn: 3600,
    refreshToken: 'rt-admin',
    refreshExpiresIn: 604800,
    user: { id: 'u-admin', email: 'admin@swasthya.test', status: 'active' },
    assignments: [
      { organizationId: 'org-1', organizationCode: 'SMOKE', facilityId: 'fac-1', facilityName: 'Smoke Central', roles },
    ],
  });
}

function renderPage(ui: React.ReactNode) {
  localStorage.setItem('swasthya.refreshToken', 'rt-admin');
  sessionStorage.setItem('swasthya.accessToken', 'at-admin');
  // Smart stub: first call is auth/refresh (session payload), everything else gets empty array.
  const sessionRes = sessionPayload(['org_admin']);
  const emptyArr = jsonOk([]);
  let callCount = 0;
  const fn = vi.fn(async () => {
    callCount++;
    return callCount === 1 ? sessionRes : emptyArr;
  });
  vi.stubGlobal('fetch', fn);
  return render(
    <MemoryRouter>
      <I18nProvider>
        <AuthProvider>
          <TenantProvider>
            {ui}
          </TenantProvider>
        </AuthProvider>
      </I18nProvider>
    </MemoryRouter>,
  );
}

describe('Admin permission gating', () => {
  it('admin roles include org_admin, hospital_admin, superadmin', () => {
    expect(ADMIN_ROLES).toContain('org_admin');
    expect(ADMIN_ROLES).toContain('hospital_admin');
    expect(ADMIN_ROLES).toContain('superadmin');
  });
});

describe('AdminUsersPage', () => {
  it('renders create button and page heading', async () => {
    renderPage(<AdminUsersPage />);
    expect(await screen.findByRole('button', { name: /create user/i })).toBeInTheDocument();
    expect(screen.getByText(/user management/i)).toBeInTheDocument();
  });
});

describe('AdminRolesPage', () => {
  it('renders roles and permissions tabs', async () => {
    renderPage(<AdminRolesPage />);
    // The "Roles" tab text matches both the tab and the heading; use role="tab".
    const tabs = await screen.findAllByRole('tab');
    expect(tabs.length).toBeGreaterThanOrEqual(2);
  });
});

describe('AdminDepartmentsPage', () => {
  it('renders create button', async () => {
    renderPage(<AdminDepartmentsPage />);
    expect(await screen.findByRole('button', { name: /create department/i })).toBeInTheDocument();
  });
});

describe('AdminServicesPage', () => {
  it('renders add service button', async () => {
    renderPage(<AdminServicesPage />);
    expect(await screen.findByRole('button', { name: /add service/i })).toBeInTheDocument();
  });
});

describe('AdminMedicationsPage', () => {
  it('renders add medication button', async () => {
    renderPage(<AdminMedicationsPage />);
    expect(await screen.findByRole('button', { name: /add medication/i })).toBeInTheDocument();
  });
});

describe('AdminSettingsPage', () => {
  it('renders facility settings heading', async () => {
    renderPage(<AdminSettingsPage />);
    expect(await screen.findByText(/facility settings/i)).toBeInTheDocument();
  });
});
