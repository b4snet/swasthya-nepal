import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { LoginPage } from '../pages/LoginPage';
import { AuthProvider } from './AuthProvider';
import { jsonError, jsonOk, stubFetch, assignments } from '../test/helpers';

function renderLogin() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('LoginPage', () => {
  it('submits real credentials to the backend login endpoint', async () => {
    const fetchMock = stubFetch(
      jsonOk({
        accessToken: 'at-1',
        tokenType: 'Bearer',
        expiresIn: 3600,
        refreshToken: 'rt-1',
        refreshExpiresIn: 604800,
        user: { id: 'u1', email: 'a@b.test', status: 'active' },
        assignments: assignments(),
      }),
    );
    const user = userEvent.setup();
    renderLogin();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.type(screen.getByLabelText(/password/i), 'secret');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(1);
    });
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/api/v1/auth/login');
    expect(JSON.parse(String(init.body))).toEqual({ email: 'a@b.test', password: 'secret' });
  });

  it('maps a 401 to a user-facing error message', async () => {
    stubFetch(jsonError(401, 'INVALID_CREDENTIALS', 'Invalid credentials.'));
    const user = userEvent.setup();
    renderLogin();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.type(screen.getByLabelText(/password/i), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/sign-in failed/i);
  });

  it('shows a rate-limit specific message on 429', async () => {
    stubFetch(jsonError(429, 'RATE_LIMITED', 'Too many attempts.'));
    const user = userEvent.setup();
    renderLogin();
    await user.type(screen.getByLabelText(/email/i), 'a@b.test');
    await user.type(screen.getByLabelText(/password/i), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/too many attempts/i);
  });

  it('maps an empty submit to the backend validation message', async () => {
    // The form is intentionally noValidate: the backend is authoritative.
    // An empty submit reaches the API and its 422 VALIDATION surfaces as a
    // user-facing message rather than being silently swallowed.
    stubFetch(jsonError(422, 'VALIDATION', 'The email field is required.'));
    const user = userEvent.setup();
    renderLogin();
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Enter your email and password.');
  });
});
