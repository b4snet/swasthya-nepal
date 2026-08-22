import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { I18nProvider } from '../i18n/I18nProvider';
import { ToastProvider } from '../context/ToastContext';
import { PharmacyPage } from './PharmacyPage';
import { InventoryPage } from './InventoryPage';
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

function sessionResponse() {
  return jsonOk({
    accessToken: 'tok_test',
    tokenType: 'Bearer',
    expiresIn: 3600,
    refreshToken: 'ref_test',
    refreshExpiresIn: 86400,
    user: { id: 'u1', email: 'admin@test.com', status: 'active' },
    assignments: assignments(),
  });
}

describe('PharmacyPage', () => {
  it('renders pharmacy heading', async () => {
    stubFetch(sessionResponse());
    renderWithProviders(<PharmacyPage />);
    const heading = await screen.findByText('Pharmacy');
    expect(heading).toBeTruthy();
  });

  it('shows prescription lookup input', async () => {
    stubFetch(sessionResponse());
    renderWithProviders(<PharmacyPage />);
    const input = await screen.findByPlaceholderText('Enter prescription ID');
    expect(input).toBeTruthy();
  });
});

describe('InventoryPage', () => {
  it('renders inventory heading', async () => {
    stubFetch(sessionResponse(), jsonOk([]), jsonOk([]));
    renderWithProviders(<InventoryPage />);
    const heading = await screen.findByText('Supply Chain');
    expect(heading).toBeTruthy();
  });

  it('shows empty state when no inventory items', async () => {
    stubFetch(sessionResponse(), jsonOk([]), jsonOk([]));
    renderWithProviders(<InventoryPage />);
    const empty = await screen.findByText('No inventory items');
    expect(empty).toBeTruthy();
  });
});
