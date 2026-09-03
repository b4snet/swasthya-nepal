import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { ResetPasswordPage } from '../pages/ResetPasswordPage';
import { jsonError, jsonOk, stubFetch } from '../test/helpers';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/reset-password/tok123']}>
      <Routes>
        <Route path="/reset-password/:token" element={<ResetPasswordPage />} />
        <Route path="/login" element={<div data-testid="login-route">login</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

async function fillPassword(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/^New password/), 'a-brand-new-password-123');
  await user.type(screen.getByLabelText(/^Confirm new password/), 'a-brand-new-password-123');
}

describe('ResetPasswordPage', () => {
  it('submits the token and password to the reset endpoint', async () => {
    const fetchMock = stubFetch(jsonOk({ message: 'Your password has been reset. You can now sign in.' }));
    const user = userEvent.setup();
    renderPage();
    await fillPassword(user);
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(1);
    });
    const call = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(call[0]).toBe('/api/v1/auth/password/reset');
    expect(JSON.parse(String(call[1].body))).toEqual({ token: 'tok123', password: 'a-brand-new-password-123' });
  });

  it('navigates to login after a successful reset', async () => {
    stubFetch(jsonOk({ message: 'Your password has been reset. You can now sign in.' }));
    const user = userEvent.setup();
    renderPage();
    await fillPassword(user);
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    expect(await screen.findByTestId('login-route')).toBeInTheDocument();
  });

  it('shows a mismatch warning and disables submit until passwords agree', async () => {
    const user = userEvent.setup();
    renderPage();
    await user.type(screen.getByLabelText(/^New password/), 'a-brand-new-password-123');
    await user.type(screen.getByLabelText(/^Confirm new password/), 'different-password-456');
    expect(screen.getByTestId('password-mismatch')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reset password' })).toBeDisabled();

    await user.clear(screen.getByLabelText(/^Confirm new password/));
    await user.type(screen.getByLabelText(/^Confirm new password/), 'a-brand-new-password-123');
    expect(screen.queryByTestId('password-mismatch')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reset password' })).toBeEnabled();
  });

  it('shows a friendly message for an invalid or expired link', async () => {
    stubFetch(jsonError(401, 'UNAUTHORIZED', 'Invalid token.'));
    const user = userEvent.setup();
    renderPage();
    await fillPassword(user);
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/invalid or has expired/i);
  });
});