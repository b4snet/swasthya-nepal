import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { I18nProvider } from '../i18n/I18nProvider';
import { jsonOk } from '../test/helpers';
import { PatientsPage } from './PatientsPage';
import { PatientRegisterPage } from './PatientRegisterPage';
import { PatientProfilePage } from './PatientProfilePage';

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

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

function renderPage(ui: React.ReactNode, entry: string = '/') {
  localStorage.setItem('swasthya.refreshToken', 'rt-test');
  sessionStorage.setItem('swasthya.accessToken', 'at-test');
  const sessionRes = sessionPayload(['hospital_admin']);
  const emptyArr = jsonOk([]);
  let callCount = 0;
  const fn = vi.fn(async () => {
    callCount++;
    return callCount === 1 ? sessionRes : emptyArr;
  });
  vi.stubGlobal('fetch', fn);
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
    expect(screen.getByRole('button', { name: /register patient/i })).toBeInTheDocument();
  });

  it('shows empty state when no patients', async () => {
    renderPage(<PatientsPage />);
    expect(await screen.findByText(/no patients found/i)).toBeInTheDocument();
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
  it('renders profile page without crashing', async () => {
    localStorage.setItem('swasthya.refreshToken', 'rt-test');
    sessionStorage.setItem('swasthya.accessToken', 'at-test');
    let callCount = 0;
    const fn = vi.fn(async () => {
      callCount++;
      if (callCount === 1) return sessionPayload(['hospital_admin']);
      return jsonOk(null);
    });
    vi.stubGlobal('fetch', fn);

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

    // Should render either loading spinner or patient data without crashing
    await waitFor(() => {
      const page = document.querySelector('.page');
      expect(page).toBeInTheDocument();
    });
  });

  it('has back to patients link', async () => {
    localStorage.setItem('swasthya.refreshToken', 'rt-test');
    sessionStorage.setItem('swasthya.accessToken', 'at-test');
    let callCount = 0;
    const fn = vi.fn(async () => {
      callCount++;
      if (callCount === 1) return sessionPayload(['hospital_admin']);
      return jsonOk(null);
    });
    vi.stubGlobal('fetch', fn);

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

    // Eventually the page should render with a back link
    await waitFor(() => {
      expect(screen.getByText(/back to patients/i)).toBeInTheDocument();
    }, { timeout: 3000 });
  });
});
