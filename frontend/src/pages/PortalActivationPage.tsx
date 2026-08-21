import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { portalActivationApi } from '../api/endpoints';
import { Alert, Button, Input, StatusChip } from '../components/ui';
import { ApiError } from '../api/client';
import './portal-activation.css';

export function PortalActivationPage() {
  const { token } = useParams<{ token: string }>();
  const navigate = useNavigate();
  const [step, setStep] = useState<'verifying' | 'setup' | 'success' | 'error'>('verifying');
  const [patientName, setPatientName] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [email, setEmail] = useState<string | null>(null);
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);

  // Password strength
  const strength = getPasswordStrength(password);
  const passwordsMatch = password === confirmPassword && confirmPassword.length > 0;
  const isValid = password.length >= 12 && passwordsMatch;

  useEffect(() => {
    if (!token) { setStep('error'); setError('No activation link provided.'); return; }
    portalActivationApi.verifyToken(token)
      .then(res => {
        const data = res as unknown as { patientName: string; expiresAt: string; email: string | null };
        setPatientName(data.patientName);
        setExpiresAt(data.expiresAt);
        setEmail(data.email);
        setStep('setup');
      })
      .catch(err => {
        setStep('error');
        setError(err instanceof ApiError ? err.message : 'Invalid or expired activation link.');
      });
  }, [token]);

  const handleActivate = async () => {
    if (!token || !isValid) return;
    setSubmitting(true);
    setError(null);
    try {
      await portalActivationApi.activate(token, password, confirmPassword);
      setStep('success');
      // Auto-redirect to portal after 3 seconds
      setTimeout(() => navigate('/portal'), 3000);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to activate account.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="portal-activation">
      <div className="portal-activation__card">
        {/* Header */}
        <div className="portal-activation__header">
          <div className="portal-activation__logo">
            <span className="portal-activation__logo-text">S</span>
          </div>
          <h1>Swasthya Patient Portal</h1>
          <p className="portal-activation__subtitle">Activate your account to access your health records</p>
        </div>

        {/* Verifying state */}
        {step === 'verifying' && (
          <div className="portal-activation__status">
            <div className="portal-activation__spinner" />
            <p>Verifying your invitation…</p>
          </div>
        )}

        {/* Error state */}
        {step === 'error' && (
          <div className="portal-activation__status">
            <div className="portal-activation__error-icon">✕</div>
            <h2>Activation Failed</h2>
            <p className="portal-activation__error-msg">{error}</p>
            <p className="portal-activation__help">Contact your hospital to request a new activation link.</p>
            <Button onClick={() => navigate('/portal')}>Go to Portal Login</Button>
          </div>
        )}

        {/* Password setup */}
        {step === 'setup' && (
          <div className="portal-activation__form">
            <div className="portal-activation__welcome">
              <p>Welcome, <strong>{patientName}</strong></p>
              {email && <p className="portal-activation__email">Activation sent to: {email}</p>}
              <p className="portal-activation__expires">This link expires: {new Date(expiresAt).toLocaleString()}</p>
            </div>

            <h2>Set Your Password</h2>
            <p className="portal-activation__hint">Choose a strong password. Your hospital staff will never see your password.</p>

            {error && <Alert tone="danger">{error}</Alert>}

            <div className="portal-activation__password-field">
              <Input
                label="Password"
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={e => setPassword(e.target.value)}
                required
                minLength={12}
                placeholder="Minimum 12 characters"
              />
              <button
                type="button"
                className="portal-activation__show-pw"
                onClick={() => setShowPassword(!showPassword)}
              >
                {showPassword ? 'Hide' : 'Show'}
              </button>
            </div>

            {password.length > 0 && (
              <div className="portal-activation__strength">
                <div className="portal-activation__strength-bar">
                  <div
                    className={`portal-activation__strength-fill portal-activation__strength-fill--${strength.level}`}
                    style={{ width: `${strength.percent}%` }}
                  />
                </div>
                <span className={`portal-activation__strength-label portal-activation__strength-label--${strength.level}`}>
                  {strength.label}
                </span>
              </div>
            )}

            <Input
              label="Confirm Password"
              type={showPassword ? 'text' : 'password'}
              value={confirmPassword}
              onChange={e => setConfirmPassword(e.target.value)}
              required
              placeholder="Re-enter your password"
            />

            {confirmPassword.length > 0 && !passwordsMatch && (
              <p className="portal-activation__mismatch">Passwords do not match</p>
            )}

            <div className="portal-activation__rules">
              <p className={password.length >= 12 ? 'portal-activation__rule--met' : ''}>At least 12 characters</p>
              <p className={/[A-Z]/.test(password) ? 'portal-activation__rule--met' : ''}>One uppercase letter</p>
              <p className={/[a-z]/.test(password) ? 'portal-activation__rule--met' : ''}>One lowercase letter</p>
              <p className={/[0-9]/.test(password) ? 'portal-activation__rule--met' : ''}>One number</p>
            </div>

            <Button
              onClick={() => void handleActivate()}
              loading={submitting}
              disabled={!isValid}
              className="portal-activation__submit"
            >
              Activate Account
            </Button>
          </div>
        )}

        {/* Success state */}
        {step === 'success' && (
          <div className="portal-activation__status portal-activation__status--success">
            <div className="portal-activation__success-icon">✓</div>
            <h2>Account Activated!</h2>
            <p>Your portal account is now active. Redirecting you to the portal…</p>
            <StatusChip tone="success" label="Active" />
            <Button onClick={() => navigate('/portal')} className="portal-activation__go-btn">
              Go to Portal Now
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}

function getPasswordStrength(pw: string): { level: 'weak' | 'fair' | 'strong'; percent: number; label: string } {
  let score = 0;
  if (pw.length >= 12) score++;
  if (pw.length >= 16) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[a-z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  if (score <= 2) return { level: 'weak', percent: 25, label: 'Weak' };
  if (score <= 4) return { level: 'fair', percent: 60, label: 'Fair' };
  return { level: 'strong', percent: 100, label: 'Strong' };
}
