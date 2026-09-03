import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { ForgotPasswordPage } from '../pages/ForgotPasswordPage';
import { jsonError, jsonOk, stubFetch } from '../test/helpers';

function renderPage() {
  return render(
    <MemoryRouter>
      <ForgotPasswordPage />
    </MemoryRouter>,
  );
}

describe('ForgotPasswordPage', () => {
  it('submits the email to the forgot-password endpoint', async () => {
    const fetchMock = stubFetch(jsonOk({ message: 'If an account exists for that email, a password-reset token has been sent.' }));
    const user = userEvent.setup();
    renderPage();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.click(screen.getByRole('button', { name: 'Send reset link' }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(1);
    });
    const call = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(call[0]).toBe('/api/v1/auth/password/forgot');
    expect(JSON.parse(String(call[1].body))).toEqual({ email: 'a@b.test' });
  });

  it('always shows the generic non-enumerating success message', async () => {
    stubFetch(jsonOk({ message: 'If an account exists for that email, a password-reset token has been sent.' }));
    const user = userEvent.setup();
    renderPage();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.click(screen.getByRole('button', { name: 'Send reset link' }));

    expect(await screen.findByTestId('forgot-success')).toBeInTheDocument();
  });

  it('surfaces an error when the request fails', async () => {
    stubFetch(jsonError(500, 'SERVER', 'Server error.'));
    const user = userEvent.setup();
    renderPage();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.click(screen.getByRole('button', { name: 'Send reset link' }));

    expect(await screen.findByRole('alert')).toBeInTheDocument();
  });
});