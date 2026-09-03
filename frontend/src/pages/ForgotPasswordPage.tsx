import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { authApi } from '../api/auth';
import { Button, Input } from '../components/ui';
import { ApiError } from '../api/client';
import './login.css';

/**
 * Staff password reset — request a single-use token (SECURITY.md §2, §5).
 * The API response is deliberately generic to prevent account enumeration
 * (ForgotPasswordRequest), so the UI always shows the same confirmation.
 */
export function ForgotPasswordPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      await authApi.forgotPassword(email.trim());
      setSent(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not request a password reset. Please try again.');
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
          <h1>Reset your password</h1>
          <p className="login__subtitle">Enter your account email and we’ll send a reset link</p>
        </div>

        {sent ? (
          <div className="stack" data-testid="forgot-success">
            <div className="alert alert--success" role="status">
              If an account exists for that email, a password-reset token has been sent.
            </div>
            <Button variant="secondary" full onClick={() => navigate('/login')}>
              Back to sign in
            </Button>
          </div>
        ) : (
          <form onSubmit={onSubmit} className="stack" noValidate>
            <Input
              label="Email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@hospital.np"
              required
            />
            {error && (
              <div className="alert alert--danger" role="alert">
                {error}
              </div>
            )}
            <Button type="submit" full loading={submitting}>
              Send reset link
            </Button>
            <p className="login__subtitle" style={{ textAlign: 'center', marginTop: 0 }}>
              <Link to="/login">Back to sign in</Link>
            </p>
          </form>
        )}
      </div>
    </main>
  );
}