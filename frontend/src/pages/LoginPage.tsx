import { useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider';
import { Button, Input } from '../components/ui';
import { ApiError } from '../api/client';
import './login.css';

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      await login(email.trim(), password);
      navigate('/', { replace: true });
    } catch (err) {
      const apiErr = err as ApiError;
      setError(
        apiErr.code === 'VALIDATION' ? 'Enter your email and password.'
        : apiErr.code === 'RATE_LIMITED' ? 'Too many attempts. Wait a moment and try again.'
        : 'Sign-in failed. Check your email and password.',
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <main className="login">
      <div className="login__card card">
        <div className="login__brand">
          <span className="login__mark" aria-hidden="true">◈</span>
          <h1>Swasthya</h1>
          <p className="muted">Hospital management — sign in to continue</p>
        </div>
        <form onSubmit={onSubmit} className="stack" noValidate>
          <Input
            label="Email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="you@hospital.example"
            required
          />
          <Input
            label="Password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
          {error && (
            <div className="alert alert--danger" role="alert">
              {error}
            </div>
          )}
          <Button type="submit" full loading={submitting}>
            Sign in
          </Button>
        </form>
      </div>
    </main>
  );
}
