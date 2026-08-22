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
  // Wait for module rail to render
  await waitFor(() => {
    expect(screen.queryByTestId('module-hospital')).not.toBeNull();
  });
}

const countModule = (key: string) => screen.queryAllByTestId(`module-${key}`).length;

describe('AppShell module-first navigation', () => {
  it('shows module rail with top-level modules', async () => {
    await renderShell(['superadmin']);
    // Core modules should always be visible
    expect(countModule('hospital')).toBeGreaterThan(0);
    expect(countModule('clinical')).toBeGreaterThan(0);
    expect(countModule('pharmacy')).toBeGreaterThan(0);
    expect(countModule('finance')).toBeGreaterThan(0);
  });

  it('hides admin module from non-admin roles', async () => {
    await renderShell(['doctor']);
    expect(countModule('admin')).toBe(0);
  });

  it('shows admin module to admin roles', async () => {
    await renderShell(['superadmin']);
    expect(countModule('admin')).toBeGreaterThan(0);
  });

  it('shows queue sub-nav inside clinical module for clinical roles', async () => {
    await renderShell(['receptionist']);
    // Click on clinical module to open its sub-nav
    const clinicalBtn = screen.getByTestId('module-clinical');
    clinicalBtn.click();
    // Wait for sub-nav to appear with Queue
    await waitFor(() => {
      expect(screen.queryByTestId('subnav-clin-queue')).not.toBeNull();
    });
  });

  it('hides billing from doctor roles in finance sub-nav', async () => {
    await renderShell(['doctor']);
    // Click finance module
    const financeBtn = screen.getByTestId('module-finance');
    financeBtn.click();
    await waitFor(() => {
      expect(screen.queryByTestId('subnav-fin-billing')).toBeNull();
    });
  });

  it('shows billing sub-nav to billing clerk', async () => {
    await renderShell(['billing_clerk']);
    const financeBtn = screen.getByTestId('module-finance');
    financeBtn.click();
    await waitFor(() => {
      expect(screen.queryByTestId('subnav-fin-billing')).not.toBeNull();
    });
  });
});
