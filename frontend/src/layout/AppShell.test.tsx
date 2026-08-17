import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { AUDIT_ROLES, BILLING_ROLES, QUEUE_ROLES } from '../auth/roles';
import { jsonOk, stubFetch } from '../test/helpers';
import { AppShell } from './AppShell';

/**
 * Mirror of backend/database/seeders/RolePermissionSeeder.php::catalog().
 * A nav gate referencing a role the backend never issues is inert (the
 * sidebar can hide a screen only after the backend grants the permission).
 */
const SEEDED_ROLES = [
  'superadmin',
  'support_agent',
  'org_admin',
  'org_finance',
  'hospital_admin',
  'branch_manager',
  'receptionist',
  'billing_clerk',
  'doctor',
  'nurse',
  'pharmacist',
  'lab_technician',
];

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
  await screen.findAllByRole('link', { name: 'Dashboard' });

  // The session is applied asynchronously (refresh fetch -> auth state ->
  // tenant roles). Dashboard is ungated and renders even during auth
  // 'loading', so waiting only for it races the role-gated assertions below.
  // Wait until the post-auth nav is computed (any role-gated destination is
  // present) before returning; every test in this file asserts a gated link.
  await waitFor(() => {
    const gated = ['Queue', 'Billing', 'Audit'].some((name) => screen.queryAllByRole('link', { name }).length > 0);
    expect(gated).toBe(true);
  });
}

const count = (name: string) => screen.queryAllByRole('link', { name }).length;

describe('AppShell role gating (UX mirror of the seeded RBAC catalog)', () => {
  it('gates only on role codes that exist in the seeded catalog', () => {
    for (const r of [...QUEUE_ROLES, ...BILLING_ROLES, ...AUDIT_ROLES]) {
      expect(SEEDED_ROLES).toContain(r);
    }
  });

  it('shows the queue to front-desk and clinical roles', async () => {
    await renderShell(['receptionist']);
    expect(count('Queue')).toBeGreaterThan(0);
    expect(count('Billing')).toBe(0);
    expect(count('Audit')).toBe(0);
  });

  it('shows billing to a billing clerk', async () => {
    await renderShell(['billing_clerk']);
    expect(count('Billing')).toBeGreaterThan(0);
    expect(count('Audit')).toBe(0);
  });

  it('hides billing and audit from a doctor', async () => {
    await renderShell(['doctor']);
    expect(count('Queue')).toBeGreaterThan(0);
    expect(count('Billing')).toBe(0);
    expect(count('Audit')).toBe(0);
  });

  it('shows the audit trail to every seeded role holding audit:view', async () => {
    for (const role of ['org_admin', 'org_finance', 'hospital_admin', 'branch_manager', 'superadmin']) {
      await renderShell([role]);
      expect(count('Audit'), `${role} should see Audit`).toBeGreaterThan(0);
    }
  });

  it('hides the audit trail from roles without audit:view', async () => {
    for (const role of ['receptionist', 'billing_clerk', 'doctor', 'nurse']) {
      await renderShell([role]);
      expect(count('Audit'), `${role} should not see Audit`).toBe(0);
    }
  });
});
