/**
 * AdminPages.test.tsx
 *
 * Tests Admin pages (Users, Roles, Departments, Services, Medications, Settings).
 * Mocks the API endpoints module directly (file-scoped vi.mock) instead of
 * globalThis.fetch — this eliminates cross-file contamination.
 */
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi, beforeEach } from 'vitest';
import { AuthProvider } from '../../auth/AuthProvider';
import { TenantProvider } from '../../context/TenantContext';
import { I18nProvider } from '../../i18n/I18nProvider';
import { AdminUsersPage } from './AdminUsersPage';
import { AdminRolesPage } from './AdminRolesPage';
import { AdminDepartmentsPage } from './AdminDepartmentsPage';
import { AdminServicesPage } from './AdminServicesPage';
import { AdminMedicationsPage } from './AdminMedicationsPage';
import { AdminSettingsPage } from './AdminSettingsPage';
import { ADMIN_ROLES } from '../../auth/roles';

// ─── Mock API endpoints (file-scoped, no cross-file leakage) ─────────────
// vi.hoisted ensures these variables are available inside the vi.mock factory.
const {
  mockAuthRefresh, mockUsersList, mockRolesList,
  mockDepartmentsList, mockServicesList, mockMedicationsList,
  mockFacilitySettingsList,
} = vi.hoisted(() => ({
  mockAuthRefresh: vi.fn(),
  mockUsersList: vi.fn(),
  mockRolesList: vi.fn(),
  mockDepartmentsList: vi.fn(),
  mockServicesList: vi.fn(),
  mockMedicationsList: vi.fn(),
  mockFacilitySettingsList: vi.fn(),
}));

vi.mock('../../api/endpoints', () => ({
  authApi: { refresh: mockAuthRefresh, login: vi.fn(), logout: vi.fn() },
  adminUsersApi: { list: mockUsersList, create: vi.fn(), grantRole: vi.fn(), revokeRole: vi.fn() },
  adminRolesApi: { list: mockRolesList },
  adminPermissionsApi: { list: vi.fn().mockResolvedValue([]) },
  adminDepartmentsApi: { list: mockDepartmentsList, create: vi.fn(), update: vi.fn(), remove: vi.fn(), show: vi.fn() },
  adminServicesApi: { list: mockServicesList, create: vi.fn(), update: vi.fn(), remove: vi.fn(), show: vi.fn() },
  adminMedicationsApi: { list: mockMedicationsList, create: vi.fn() },
  adminFacilitySettingsApi: { list: mockFacilitySettingsList, update: vi.fn(), remove: vi.fn() },
  adminOrgsApi: { list: vi.fn().mockResolvedValue([]), show: vi.fn() },
  adminFacilitiesApi: { list: vi.fn().mockResolvedValue([]), show: vi.fn(), create: vi.fn() },
  adminStaffApi: { list: vi.fn().mockResolvedValue([]), show: vi.fn(), create: vi.fn(), update: vi.fn() },
  modulesApi: { catalog: vi.fn(), enabled: vi.fn(), check: vi.fn() },
  hospitalBrandingApi: { get: vi.fn(), update: vi.fn(), forDocument: vi.fn() },
  numberingApi: { types: vi.fn(), list: vi.fn(), create: vi.fn(), update: vi.fn(), preview: vi.fn(), generate: vi.fn() },
}));

afterEach(() => { vi.restoreAllMocks(); vi.resetModules(); });

function sessionPayload(roles: string[]) {
  return {
    accessToken: 'at-admin',
    tokenType: 'Bearer',
    expiresIn: 3600,
    refreshToken: 'rt-admin',
    refreshExpiresIn: 604800,
    user: { id: 'u-admin', email: 'admin@swasthya.test', status: 'active' },
    assignments: [
      { organizationId: 'org-1', organizationCode: 'SMOKE', facilityId: 'fac-1', facilityName: 'Smoke Central', roles },
    ],
  };
}

beforeEach(() => {
  localStorage.setItem('swasthya.refreshToken', 'rt-admin');
  sessionStorage.setItem('swasthya.accessToken', 'at-admin');
  mockAuthRefresh.mockResolvedValue(sessionPayload(['org_admin']));
  mockUsersList.mockResolvedValue([]);
  mockRolesList.mockResolvedValue([]);
  mockDepartmentsList.mockResolvedValue([]);
  mockServicesList.mockResolvedValue([]);
  mockMedicationsList.mockResolvedValue([]);
  mockFacilitySettingsList.mockResolvedValue({});
});

function renderPage(ui: React.ReactNode) {
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
    const tabs = await screen.findAllByRole('tab');
    expect(tabs.length).toBeGreaterThanOrEqual(2);
  });
});

describe('AdminDepartmentsPage', () => {
  it('renders create button', async () => {
    renderPage(<AdminDepartmentsPage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /create department/i })).toBeInTheDocument();
    }, { timeout: 5000 });
  });
});

describe('AdminServicesPage', () => {
  it('renders add service button', async () => {
    renderPage(<AdminServicesPage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add service/i })).toBeInTheDocument();
    }, { timeout: 5000 });
  });
});

describe('AdminMedicationsPage', () => {
  it('renders add medication button', async () => {
    renderPage(<AdminMedicationsPage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add medication/i })).toBeInTheDocument();
    }, { timeout: 5000 });
  });
});

describe('AdminSettingsPage', () => {
  it('renders facility settings heading', async () => {
    renderPage(<AdminSettingsPage />);
    expect(await screen.findByText(/facility settings/i)).toBeInTheDocument();
  });
});
