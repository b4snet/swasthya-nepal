import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
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
    user: { id: 'u-1', email: 'testuser@swasthya.test', status: 'active' },
    assignments: [
      { organizationId: 'org-1', organizationCode: 'SMOKE', facilityId: 'fac-1', facilityName: 'Smoke Central', roles },
    ],
  });
}

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
  await screen.findAllByTestId('user-menu-trigger');
}

describe('User account menu', () => {
  it('shows user initials on the trigger chip', async () => {
    await renderShell(['hospital_admin']);
    const trigger = screen.getByTestId('user-menu-trigger');
    expect(trigger).toHaveTextContent('TE');
    expect(trigger).toHaveAttribute('title', 'testuser@swasthya.test');
  });

  it('opens the dropdown on click with user email', async () => {
    const user = userEvent.setup();
    await renderShell(['hospital_admin']);
    await user.click(screen.getByTestId('user-menu-trigger'));
    const dropdown = screen.getByTestId('user-menu-dropdown');
    expect(dropdown).toBeInTheDocument();
    expect(screen.getByText('testuser@swasthya.test')).toBeInTheDocument();
  });

  it('shows a sign-out option in the dropdown', async () => {
    const user = userEvent.setup();
    await renderShell(['hospital_admin']);
    await user.click(screen.getByTestId('user-menu-trigger'));
    expect(screen.getByTestId('user-menu-logout')).toHaveTextContent(/sign out/i);
  });

  it('shows a confirmation dialog before logging out', async () => {
    const user = userEvent.setup();
    await renderShell(['hospital_admin']);
    await user.click(screen.getByTestId('user-menu-trigger'));
    await user.click(screen.getByTestId('user-menu-logout'));
    // The dialog should appear with confirmation text.
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText(/are you sure/i)).toBeInTheDocument();
  });

  it('closes dropdown on Escape key', async () => {
    const user = userEvent.setup();
    await renderShell(['hospital_admin']);
    await user.click(screen.getByTestId('user-menu-trigger'));
    expect(screen.getByTestId('user-menu-dropdown')).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(screen.queryByTestId('user-menu-dropdown')).not.toBeInTheDocument();
  });

  it('does not show dropdown initially', async () => {
    await renderShell(['hospital_admin']);
    expect(screen.queryByTestId('user-menu-dropdown')).not.toBeInTheDocument();
  });
});
