import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { authApi } from '../api/auth';
import { Button, Input } from '../components/ui';
import { ApiError } from '../api/client';
import './login.css';

/**
 * Staff password reset completion (SECURITY.md §2, §5): the emailed token is
 * single-use and short-lived. Confirmation is enforced client-side only — the
 * API accepts just {token, password} (strict unknown-field mode), so the raw
 * password field is never echoed back.
 */
export function ResetPasswordPage() {
  const { token } = useParams<{ token: string }>();
  const navigate = useNavigate();
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const passwordsMatch = password === confirmPassword && confirmPassword.length > 0;
  const isValid = (password.length >= 12) && passwordsMatch;

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!token || !isValid || submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      await authApi.resetPassword(token, password);
      navigate('/login', { replace: true });
    } catch (err) {
      const apiErr = err as ApiError;
      setError(apiErr.code === 'UNAUTHORIZED' || apiErr.code === 'VALIDATION'
        ? 'This reset link is invalid or has expired. Please request a new one.'
        : apiErr.message ?? 'Could not reset your password. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <main className="login">
      <div className="login__card card">
        <div className="login__brand">
          <div className="login__mark-wrap" aria-hidden="true">
            <svg width="32" height="32" viewBox="0 0 28 28" fill="none">
              <rect width="28" height="28" rx="6" fill="#0f766e"/>
              <path d="M8 14h12M14 8v12" stroke="#fff" strokeWidth="2.2" strokeLinecap="round"/>
            </svg>
          </div>
          <h1>Choose a new password</h1>
          <p className="login__subtitle">At least 12 characters — make it strong and unique</p>
        </div>

        <form onSubmit={onSubmit} className="stack" noValidate>
          <div className="stack" style={{ gap: '0.5rem' }}>
            <Input
              label="New password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Minimum 12 characters"
              required
              minLength={12}
            />
            <button
              type="button"
              onClick={() => setShowPassword(v => !v)}
              className="btn btn--link"
              style={{ alignSelf: 'flex-start' }}
            >
              {showPassword ? 'Hide password' : 'Show password'}
            </button>
          </div>

          <Input
            label="Confirm new password"
            type={showPassword ? 'text' : 'password'}
            autoComplete="new-password"
            value={confirmPassword}
            onChange={(e) => setConfirmPassword(e.target.value)}
            placeholder="Re-enter your password"
            required
          />

          {confirmPassword.length > 0 && !passwordsMatch && (
            <div className="alert alert--warning" role="alert" data-testid="password-mismatch">
              Passwords do not match
            </div>
          )}

          {error && (
            <div className="alert alert--danger" role="alert">
              {error}
            </div>
          )}

          <Button type="submit" full loading={submitting} disabled={!isValid}>
            Reset password
          </Button>
          <p className="login__subtitle" style={{ textAlign: 'center', marginTop: 0 }}>
            <Link to="/login">Back to sign in</Link>
          </p>
        </form>
      </div>
    </main>
  );
}