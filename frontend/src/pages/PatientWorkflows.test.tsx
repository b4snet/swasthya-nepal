/**
 * PatientWorkflows.test.tsx
 *
 * Tests PatientWorkflows pages (PatientsPage, PatientRegisterPage, PatientProfilePage).
 * Mocks the API endpoints module directly (file-scoped vi.mock) instead of
 * globalThis.fetch — this eliminates cross-file contamination.
 */
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi, beforeEach } from 'vitest';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { I18nProvider } from '../i18n/I18nProvider';
import { PatientsPage } from './PatientsPage';
import { PatientRegisterPage } from './PatientRegisterPage';
import { PatientProfilePage } from './PatientProfilePage';

// ─── Mock API endpoints (file-scoped, no cross-file leakage) ─────────────
// vi.hoisted ensures these variables are available inside the vi.mock factory,
// which is hoisted to the top of the file by vitest.
const { mockAuthRefresh, mockPatientsList, mockPatientsShow } = vi.hoisted(() => ({
  mockAuthRefresh: vi.fn(),
  mockPatientsList: vi.fn(),
  mockPatientsShow: vi.fn(),
}));

vi.mock('../api/endpoints', () => ({
  authApi: { refresh: mockAuthRefresh, login: vi.fn(), logout: vi.fn() },
  patientsApi: {
    list: mockPatientsList,
    show: mockPatientsShow,
    create: vi.fn(),
    timeline: vi.fn(),
    search: vi.fn(),
    update: vi.fn(),
    identifiers: vi.fn(),
    addIdentifier: vi.fn(),
    contacts: vi.fn(),
    addContact: vi.fn(),
    updateContact: vi.fn(),
    diagnoses: vi.fn(),
    prescriptions: vi.fn(),
    allergies: vi.fn(),
    medications: vi.fn(),
    admissions: vi.fn(),
    documents: vi.fn(),
    labOrders: vi.fn(),
    radiologyOrders: vi.fn(),
    referrals: vi.fn(),
    followUps: vi.fn(),
    importTemplate: vi.fn(),
    importUpload: vi.fn(),
    importShow: vi.fn(),
    importMapping: vi.fn(),
    importPreview: vi.fn(),
    importExecute: vi.fn(),
    importList: vi.fn(),
    sendPortalInvite: vi.fn(),
  },
}));

afterEach(() => { vi.restoreAllMocks(); vi.resetModules(); });

function sessionPayload(roles: string[]) {
  return {
    accessToken: 'at-test',
    tokenType: 'Bearer',
    expiresIn: 3600,
    refreshToken: 'rt-test',
    refreshExpiresIn: 604800,
    user: { id: 'u-1', email: 'user@swasthya.test', status: 'active' },
    assignments: [
      { organizationId: 'org-1', organizationCode: 'SMOKE', facilityId: 'fac-1', facilityName: 'Smoke Central', roles },
    ],
  };
}

beforeEach(() => {
  localStorage.setItem('swasthya.refreshToken', 'rt-test');
  sessionStorage.setItem('swasthya.accessToken', 'at-test');
  mockAuthRefresh.mockResolvedValue(sessionPayload(['hospital_admin']));
  mockPatientsList.mockResolvedValue([]);
  mockPatientsShow.mockResolvedValue(null);
});

function renderPage(ui: React.ReactNode, entry: string = '/') {
  return render(
    <MemoryRouter initialEntries={[entry]}>
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

describe('PatientsPage', () => {
  it('renders search input and register button', async () => {
    renderPage(<PatientsPage />);
    expect(await screen.findByPlaceholderText(/search by name or mrn/i)).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /register patient/i }).length).toBeGreaterThanOrEqual(1);
  });

  it('shows empty state when no patients', async () => {
    renderPage(<PatientsPage />);
    await waitFor(() => {
      expect(screen.getByText(/no patients found/i)).toBeInTheDocument();
    }, { timeout: 5000 });
  });

  it('renders with proper heading', async () => {
    renderPage(<PatientsPage />);
    expect(await screen.findByRole('heading', { name: /patients/i })).toBeInTheDocument();
  });
});

describe('PatientRegisterPage', () => {
  it('renders registration form with required fields', async () => {
    renderPage(<PatientRegisterPage />);
    expect(await screen.findByRole('heading', { name: /register patient/i })).toBeInTheDocument();
    expect(screen.getByLabelText(/full name/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/date of birth/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/sex/i)).toBeInTheDocument();
  });

  it('shows emergency contact section', async () => {
    renderPage(<PatientRegisterPage />);
    expect(await screen.findByText(/emergency contact/i)).toBeInTheDocument();
  });

  it('shows government identifier section', async () => {
    renderPage(<PatientRegisterPage />);
    expect(await screen.findByText(/government identifier/i)).toBeInTheDocument();
  });

  it('has cancel button', async () => {
    renderPage(<PatientRegisterPage />);
    expect(await screen.findByRole('button', { name: /cancel/i })).toBeInTheDocument();
  });
});

describe('PatientProfilePage', () => {
  const mockPatient = {
    id: 'p-1', fullName: 'Test Patient', mrn: 'MRN-0001',
    dateOfBirth: '1990-01-15', sex: 'male', bloodGroup: 'O+',
    status: 'active', createdAt: '2025-01-01T00:00:00Z',
  };

  beforeEach(() => {
    mockPatientsShow.mockResolvedValue(mockPatient);
  });

  it('renders profile page without crashing', async () => {
    render(
      <MemoryRouter initialEntries={['/patients/p-1']}>
        <I18nProvider>
          <AuthProvider>
            <TenantProvider>
              <PatientProfilePage />
            </TenantProvider>
          </AuthProvider>
        </I18nProvider>
      </MemoryRouter>,
    );
    await waitFor(() => {
      expect(document.querySelector('.page')).toBeInTheDocument();
    });
  });

  it('has back to patients link', async () => {
    render(
      <MemoryRouter initialEntries={['/patients/p-1']}>
        <I18nProvider>
          <AuthProvider>
            <TenantProvider>
              <PatientProfilePage />
            </TenantProvider>
          </AuthProvider>
        </I18nProvider>
      </MemoryRouter>,
    );
    await waitFor(() => {
      expect(screen.getByText(/back to patients/i)).toBeInTheDocument();
    }, { timeout: 3000 });
  });
});
