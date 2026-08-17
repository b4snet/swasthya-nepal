import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { jsonOk, stubFetch } from '../test/helpers';
import { I18nProvider } from './I18nProvider';
import { AppShell } from '../layout/AppShell';

/**
 * Phase 22 localization — the REAL app shell (nav, context switcher, sign
 * out) renders in Nepali Devanagari when the provider locale is `ne`, and
 * the html lang attribute drives the Devanagari font stack.
 */
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

async function renderLocalizedShell(roles: string[]) {
  localStorage.setItem('swasthya.refreshToken', 'rt-test');
  sessionStorage.setItem('swasthya.accessToken', 'at-test');
  stubFetch(sessionPayload(roles));
  render(
    <MemoryRouter>
      <I18nProvider>
        <AuthProvider>
          <TenantProvider>
            <AppShell />
          </TenantProvider>
        </AuthProvider>
      </I18nProvider>
    </MemoryRouter>,
  );
  await screen.findAllByRole('link', { name: 'Dashboard' });
}

describe('localized app shell (Phase 22)', () => {
  it('renders the shell in English by default', async () => {
    await renderLocalizedShell(['receptionist']);
    // Sidebar + bottom nav render each destination twice — count both.
    expect(screen.getAllByRole('link', { name: 'Queue' }).length).toBeGreaterThan(0);
    expect(document.documentElement.lang).toBe('en');
  });

  it('toggles the whole shell into Nepali Devanagari and back', async () => {
    const user = userEvent.setup();
    await renderLocalizedShell(['receptionist']);
    await user.click(screen.getByTestId('lang-toggle'));
    expect(document.documentElement.lang).toBe('ne');
    expect(screen.getAllByRole('link', { name: 'कतार' }).length).toBeGreaterThan(0); // Queue
    expect(screen.getAllByRole('link', { name: 'बिरामीहरू' }).length).toBeGreaterThan(0); // Patients
    expect(screen.getByRole('button', { name: 'साइन आउट' })).toBeTruthy(); // Sign out
    await user.click(screen.getByTestId('lang-toggle'));
    expect(document.documentElement.lang).toBe('en');
    expect(screen.getAllByRole('link', { name: 'Queue' }).length).toBeGreaterThan(0);
  });
});
