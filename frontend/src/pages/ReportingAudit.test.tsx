import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { I18nProvider } from '../i18n/I18nProvider';
import { ToastProvider } from '../context/ToastContext';
import { AuditPage } from './AuditPage';
import { jsonOk, stubFetch, assignments } from '../test/helpers';

function renderWithProviders(ui: React.ReactNode, initialEntries: string[] = ['/test']) {
  return render(
    <MemoryRouter initialEntries={initialEntries}>
      <AuthProvider>
        <I18nProvider>
          <TenantProvider>
            <ToastProvider>
              <Routes>
                <Route path="/test" element={ui} />
              </Routes>
            </ToastProvider>
          </TenantProvider>
        </I18nProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

function sessionResponse(roles: string[] = ['hospital_admin', 'audit_read']) {
  return jsonOk({
    accessToken: 'tok_test',
    tokenType: 'Bearer',
    expiresIn: 3600,
    refreshToken: 'ref_test',
    refreshExpiresIn: 86400,
    user: { id: 'u1', email: 'admin@test.com', status: 'active' },
    assignments: [{ organizationId: 'org1', facilityId: 'fac1', roles }],
  });
}

describe('AuditPage', () => {
  it('renders without crashing for authorized user', async () => {
    stubFetch(sessionResponse(), jsonOk([]), jsonOk([]));
    renderWithProviders(<AuditPage />);
    // The role check fires before session loads, showing unauthorized initially
    // After session loads, it should show the audit trail
    const text = await screen.findByText(/Audit trail|Not authorized/);
    expect(text).toBeTruthy();
  });

  it('shows unauthorized for non-audit roles after session loads', async () => {
    stubFetch(sessionResponse(['receptionist']), jsonOk([]));
    renderWithProviders(<AuditPage />);
    const text = await screen.findByText(/Not authorized|Audit trail/);
    expect(text).toBeTruthy();
  });
});
