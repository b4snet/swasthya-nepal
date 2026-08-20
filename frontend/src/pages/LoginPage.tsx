import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider';
import { useI18n } from '../i18n/I18nProvider';
import { Button, Input } from '../components/ui';
import { ApiError } from '../api/client';
import './login.css';

export function LoginPage() {
  const { login, sessionExpiredReason, clearExpiredReason } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Auto-dismiss the expired banner after 8 seconds.
  useEffect(() => {
    if (!sessionExpiredReason) return;
    const timer = setTimeout(clearExpiredReason, 8000);
    return () => clearTimeout(timer);
  }, [sessionExpiredReason, clearExpiredReason]);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    clearExpiredReason();
    try {
      await login(email.trim(), password);
      navigate('/', { replace: true });
    } catch (err) {
      const apiErr = err as ApiError;
      setError(
        apiErr.code === 'VALIDATION' ? t('login.validationError')
        : apiErr.code === 'RATE_LIMITED' ? t('login.rateLimited')
        : t('login.failed'),
      );
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
              <rect width="28" height="28" rx="6" fill="#1570ef"/>
              <path d="M8 14h12M14 8v12" stroke="#fff" strokeWidth="2.2" strokeLinecap="round"/>
            </svg>
          </div>
          <h1>{t('app.name')}</h1>
          <p className="login__subtitle">{t('login.subtitle')}</p>
        </div>

        {sessionExpiredReason && (
          <div className="alert alert--warning login__expired" role="alert" data-testid="session-expired-banner">
            {sessionExpiredReason === 'expired'
              ? t('login.sessionExpired')
              : t('login.sessionRevoked')}
          </div>
        )}

        <form onSubmit={onSubmit} className="stack" noValidate>
          <Input
            label={t('login.email')}
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder={t('login.emailPlaceholder')}
            required
          />
          <Input
            label={t('login.password')}
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
            {t('login.signIn')}
          </Button>
        </form>
      </div>
    </main>
  );
}
