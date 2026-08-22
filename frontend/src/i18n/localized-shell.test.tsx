import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { jsonOk, stubFetch } from '../test/helpers';
import { I18nProvider } from './I18nProvider';
import { AppShell } from '../layout/AppShell';

/**
 * Phase 22 localization — the REAL app shell (module rail, sub-nav, context
 * switcher, sign out) renders in Nepali Devanagari when the provider locale
 * is `ne`, and the html lang attribute drives the Devanagari font stack.
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
  await waitFor(() => {
    expect(screen.queryByTestId('module-hospital')).not.toBeNull();
  });
}

describe('localized app shell (Phase 22)', () => {
  it('renders the shell in English by default', async () => {
    await renderLocalizedShell(['receptionist']);
    // Module rail renders with English labels
    expect(screen.getByTestId('module-hospital')).toHaveAttribute('aria-label', 'Hospital');
    expect(screen.getByTestId('module-clinical')).toHaveAttribute('aria-label', 'Clinical');
    expect(document.documentElement.lang).toBe('en');
  });

  it('toggles the whole shell into Nepali Devanagari and back', async () => {
    const user = userEvent.setup();
    await renderLocalizedShell(['receptionist']);
    await user.click(screen.getByTestId('lang-toggle'));
    expect(document.documentElement.lang).toBe('ne');
    // Module labels are now in Nepali
    expect(screen.getByTestId('module-hospital')).toHaveAttribute('aria-label', 'अस्पताल');
    expect(screen.getByTestId('module-clinical')).toHaveAttribute('aria-label', 'क्लिनिकल');
    expect(screen.getByRole('button', { name: 'साइन आउट' })).toBeTruthy();
    await user.click(screen.getByTestId('lang-toggle'));
    expect(document.documentElement.lang).toBe('en');
    expect(screen.getByTestId('module-hospital')).toHaveAttribute('aria-label', 'Hospital');
  });
});
