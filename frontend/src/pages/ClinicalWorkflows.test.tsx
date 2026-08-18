import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { I18nProvider } from '../i18n/I18nProvider';
import { ToastProvider } from '../context/ToastContext';
import { FollowUpList } from '../components/FollowUpList';
import { jsonOk, stubFetch, assignments } from '../test/helpers';

function renderWithProviders(ui: React.ReactNode, initialEntries: string[] = ['/test']) {
  return render(
    <MemoryRouter initialEntries={initialEntries}>
      <AuthProvider>
        <I18nProvider>
          <TenantProvider>
            <ToastProvider>
              <Routes>
                <Route path="/test/:id?" element={ui} />
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

describe('FollowUpList', () => {
  it('renders heading and empty state', async () => {
    stubFetch(sessionResponse(), jsonOk([]), jsonOk([]));
    renderWithProviders(<FollowUpList encounterId="enc-1" />);
    const heading = await screen.findByText('Follow-ups');
    expect(heading).toBeTruthy();
    const empty = await screen.findByText('No follow-ups');
    expect(empty).toBeTruthy();
  });

  it('shows empty state message', async () => {
    stubFetch(sessionResponse(), jsonOk([]), jsonOk([]));
    renderWithProviders(<FollowUpList encounterId="enc-1" />);
    const message = await screen.findByText('Follow-up plans will appear here.');
    expect(message).toBeTruthy();
  });
});
