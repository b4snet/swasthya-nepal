import { useState } from 'react';
import {
  Shield, Lock, Key, CheckCircle2,
  Database, Globe, Server, Smartphone, Activity,
  Bell, RefreshCcw, Scan, Fingerprint,
  ShieldCheck, ShieldAlert, Bug, Code, Network,
} from 'lucide-react';
import './security-harden.css';

/* ────────────────── Status dot ────────────────── */
function SDot({ status }: { status: 'pass' | 'warn' | 'fail' | 'info' }) {
  const colors = { pass: '#059669', warn: '#d97706', fail: '#dc2626', info: '#2563eb' };
  return <span className="sh-dot" style={{ background: colors[status] }} />;
}

/* ────────────────── Finding card ────────────────── */
interface Finding {
  id: string;
  title: string;
  severity: 'critical' | 'high' | 'medium' | 'low' | 'info';
  category: string;
  status: 'fixed' | 'justified' | 'not-exposed' | 'residual';
  evidence: string;
  fix: string;
}

function FindingRow({ f }: { f: Finding }) {
  const sevColors: Record<string, string> = {
    critical: '#dc2626', high: '#ea580c', medium: '#d97706', low: '#059669', info: '#2563eb',
  };
  const statusColors: Record<string, string> = {
    fixed: '#059669', justified: '#2563eb', 'not-exposed': '#6b7280', residual: '#d97706',
  };
  return (
    <div className="sh-finding">
      <div className="sh-finding__top">
        <span className="sh-finding__id">{f.id}</span>
        <span className="sh-finding__sev" style={{ color: sevColors[f.severity] }}>
          {f.severity.toUpperCase()}
        </span>
        <span className="sh-finding__cat">{f.category}</span>
        <span className="sh-finding__status" style={{ color: statusColors[f.status] }}>
          {f.status}
        </span>
      </div>
      <span className="sh-finding__title">{f.title}</span>
      <div className="sh-finding__detail">
        <span className="sh-finding__label">Evidence:</span> {f.evidence}
      </div>
      <div className="sh-finding__detail">
        <span className="sh-finding__label">Remediation:</span> {f.fix}
      </div>
    </div>
  );
}

/* ═══════════════ Main Page ═══════════════ */
export default function SecurityHardeningPage() {
  const [tab, setTab] = useState<'overview' | 'findings' | 'attack-surface' | 'auth' | 'rls' | 'frontend'>('overview');

  const findings: Finding[] = [
    {
      id: 'SEC-001', title: 'Refresh token stored in localStorage (not httpOnly cookie)',
      severity: 'medium', category: 'Auth', status: 'justified',
      evidence: 'Refresh token accessible via JS (localStorage). Access token in sessionStorage. Documented tradeoff in SECURITY.md §4 and FRONTEND_FOUNDATION_REPORT.md §4.',
      fix: 'Documented as accepted risk. XSS is mitigated by React auto-escaping, CSP headers, and no dangerouslySetInnerHTML in clinical paths. httpOnly cookie flow planned for future phase.',
    },
    {
      id: 'SEC-002', title: 'PrintPreview document.write() cross-origin fallback',
      severity: 'low', category: 'Frontend', status: 'not-exposed',
      evidence: 'PrintPreviewModal.tsx uses window.open + document.write(html) as a cross-origin iframe fallback. HTML is generated from component state, not user input.',
      fix: 'No user-controlled input reaches document.write. Acceptable for print-only context. React renders all clinical content.',
    },
    {
      id: 'SEC-003', title: 'ILIKE name search performance under RLS (147-158ms at 1M rows)',
      severity: 'low', category: 'Performance', status: 'justified',
      evidence: 'NATIONAL_SCALE.md §1 measured: tenant-scoped ILIKE defeats facility-prefixed index. Bitmap scan over ~50K tenant rows.',
      fix: 'Known hot spot documented. Facility-required contexts would enable trgm index. Not a security finding but documented for completeness.',
    },
    {
      id: 'SEC-004', title: 'ReidentifyPatients CLI command present in repo',
      severity: 'info', category: 'CLI', status: 'not-exposed',
      evidence: 'backend/app/Console/Commands/ReidentifyPatients.php — administrative CLI tool for patient ID migration. Uses parameterized queries.',
      fix: 'CLI-only, requires server access. Not exposed via HTTP. Uses parameterized SQL. Acceptable for admin tooling.',
    },
  ];

  const attackSurface = [
    { area: 'Web Application', icon: Globe, items: ['SPA (React + Vite)', 'PWA service worker', 'Print preview', 'Command palette'], risk: 'medium' },
    { area: 'REST API', icon: Server, items: ['614 routes', '85 controllers', 'Sanctum auth', 'Rate limiting'], risk: 'medium' },
    { area: 'Authentication', icon: Key, items: ['Email/password', 'MFA (TOTP)', 'Password reset', 'Portal login'], risk: 'high' },
    { area: 'Patient Portal', icon: Smartphone, items: ['Portal activation', 'Profile management', 'Self-service actions'], risk: 'high' },
    { area: 'FHIR / Interop', icon: Network, items: ['FHIR R4 read APIs', 'OAuth2 partners', 'Egress allowlist'], risk: 'medium' },
    { area: 'File Storage', icon: Database, items: ['Document uploads', 'Signed URLs', 'Export files'], risk: 'medium' },
    { area: 'Realtime', icon: Activity, items: ['WebSocket connections', 'Broadcast events', 'Presence'], risk: 'low' },
    { area: 'Telemedicine', icon: Scan, items: ['Video sessions', 'Waiting room', 'Session management'], risk: 'medium' },
    { area: 'Notifications', icon: Bell, items: ['Push notifications', 'Template engine', 'Mass notification'], risk: 'low' },
    { area: 'Admin', icon: Shield, items: ['Staff management', 'Role assignment', 'Facility config'], risk: 'high' },
  ];

  const authControls = [
    { name: 'Password Hashing', desc: 'Argon2id (default) with bcrypt fallback', status: 'pass', icon: Lock },
    { name: 'MFA (TOTP)', desc: 'Time-based one-time password with backup codes', status: 'pass', icon: Fingerprint },
    { name: 'Session Expiry', desc: 'Short-lived access tokens, refresh token rotation', status: 'pass', icon: Key },
    { name: 'Account Lockout', desc: 'DB-backed per-account failure limiting', status: 'pass', icon: ShieldCheck },
    { name: 'Password Reset', desc: 'Single-use token with expiry, throttled', status: 'pass', icon: RefreshCcw },
    { name: 'Onboarding Gate', desc: 'First-login flow with profile completion', status: 'pass', icon: CheckCircle2 },
    { name: 'Token Refresh', desc: 'Sanctum bearer tokens, hashed at rest', status: 'pass', icon: Shield },
    { name: 'Portal Auth', desc: 'Separate token namespace for patient portal', status: 'pass', icon: ShieldCheck },
  ];

  const rlsSummary = [
    { metric: 'RLS Policies', value: '508', status: 'pass' },
    { metric: 'FORCE RLS Tables', value: '17', status: 'pass' },
    { metric: 'ENABLE RLS Tables', value: '16', status: 'pass' },
    { metric: 'Cross-Tenant Isolation', value: 'Verified', status: 'pass' },
    { metric: 'Cross-Facility Isolation', value: 'Verified', status: 'pass' },
    { metric: 'Claim Forgery Test', value: 'Blocked', status: 'pass' },
    { metric: 'Missing Claims Test', value: 'Returns 0 rows', status: 'pass' },
    { metric: 'Wrong Tenant Test', value: 'Returns 0 rows', status: 'pass' },
  ];

  const frontendControls = [
    { name: 'React Auto-Escaping', desc: 'All clinical content rendered via JSX (no raw HTML)', status: 'pass' },
    { name: 'No dangerouslySetInnerHTML', desc: 'Zero instances in clinical paths', status: 'pass' },
    { name: 'No eval() / new Function()', desc: 'No dynamic code execution in frontend', status: 'pass' },
    { name: 'No Source Maps', desc: 'Build output contains zero .map files', status: 'pass' },
    { name: 'CORS Strict Allowlist', desc: 'No wildcard origins, config-driven', status: 'pass' },
    { name: 'Auth Token Isolation', desc: 'Staff and portal tokens use separate localStorage keys', status: 'pass' },
    { name: 'No Secrets in Frontend', desc: 'Zero VITE_*_SECRET or VITE_*_KEY environment variables', status: 'pass' },
    { name: 'PWA Cache Policy', desc: 'Service worker caches shell only, never API/auth data', status: 'pass' },
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Security Overview' },
    { key: 'findings' as const, label: 'Findings (4)' },
    { key: 'attack-surface' as const, label: 'Attack Surface' },
    { key: 'auth' as const, label: 'Authentication' },
    { key: 'rls' as const, label: 'RLS & Authorization' },
    { key: 'frontend' as const, label: 'Frontend Security' },
  ];

  return (
    <div className="security-page">
      <div className="sh-header">
        <div className="sh-header__left">
          <h1 className="sh-title">Security Hardening</h1>
          <p className="sh-subtitle">Final security audit, self-pentest findings and hardening evidence</p>
        </div>
        <div className="sh-header__right">
          <div className="sh-status-pill sh-status-pill--ok">
            <ShieldCheck size={14} />
            <span>0 Critical / 0 High</span>
          </div>
        </div>
      </div>

      <div className="sh-census">
        <div className="sh-census-card">
          <div className="sh-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><ShieldCheck size={18} /></div>
          <div className="sh-census-card__info">
            <span className="sh-census-card__value">0</span>
            <span className="sh-census-card__label">Critical</span>
          </div>
        </div>
        <div className="sh-census-card">
          <div className="sh-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><ShieldCheck size={18} /></div>
          <div className="sh-census-card__info">
            <span className="sh-census-card__value">0</span>
            <span className="sh-census-card__label">High</span>
          </div>
        </div>
        <div className="sh-census-card">
          <div className="sh-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><ShieldAlert size={18} /></div>
          <div className="sh-census-card__info">
            <span className="sh-census-card__value">1</span>
            <span className="sh-census-card__label">Medium</span>
          </div>
        </div>
        <div className="sh-census-card">
          <div className="sh-census-card__icon" style={{ background: '#f0f5ff', color: '#2563eb' }}><Shield size={18} /></div>
          <div className="sh-census-card__info">
            <span className="sh-census-card__value">3</span>
            <span className="sh-census-card__label">Low / Info</span>
          </div>
        </div>
        <div className="sh-census-card">
          <div className="sh-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Database size={18} /></div>
          <div className="sh-census-card__info">
            <span className="sh-census-card__value">508</span>
            <span className="sh-census-card__label">RLS Policies</span>
          </div>
        </div>
        <div className="sh-census-card">
          <div className="sh-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Lock size={18} /></div>
          <div className="sh-census-card__info">
            <span className="sh-census-card__value">8/8</span>
            <span className="sh-census-card__label">Auth Controls</span>
          </div>
        </div>
      </div>

      <div className="sh-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`sh-tab ${tab === t.key ? 'sh-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="sh-content">
        {tab === 'overview' && (
          <div className="sh-section">
            <div className="sh-section__head"><Shield size={16} strokeWidth={1.75} /><span>Security Posture Summary</span></div>
            <div className="sh-overview-grid">
              <div className="sh-overview-card">
                <h4 className="sh-overview-card__title">Self-Tested Findings</h4>
                <p className="sh-overview-card__desc">All findings from authorized self-testing. Zero critical/high. One medium (documented tradeoff). Three low/info (justified, not exposed).</p>
                <div className="sh-overview-card__status sh-overview-card__status--ok">PASS — No blocking findings</div>
              </div>
              <div className="sh-overview-card">
                <h4 className="sh-overview-card__title">External Penetration Test</h4>
                <p className="sh-overview-card__desc">LOCALLY SELF-TESTED ONLY. An independent external penetration test by a qualified security firm is required before production deployment.</p>
                <div className="sh-overview-card__status sh-overview-card__status--warn">REQUIRED — Not yet performed</div>
              </div>
              <div className="sh-overview-card">
                <h4 className="sh-overview-card__title">PHI Exposure</h4>
                <p className="sh-overview-card__desc">No patient names, clinical narrative, credentials, or payment secrets found in logs, metrics, traces, or frontend code.</p>
                <div className="sh-overview-card__status sh-overview-card__status--ok">PASS — PHI-safe logging verified</div>
              </div>
              <div className="sh-overview-card">
                <h4 className="sh-overview-card__title">Secret Exposure</h4>
                <p className="sh-overview-card__desc">No hardcoded secrets. .env files gitignored. Only .example files in repo. Zero secrets in git history.</p>
                <div className="sh-overview-card__status sh-overview-card__status--ok">PASS — No secrets exposed</div>
              </div>
            </div>
          </div>
        )}

        {tab === 'findings' && (
          <div className="sh-section">
            <div className="sh-section__head"><Bug size={16} strokeWidth={1.75} /><span>Security Findings</span></div>
            {findings.map((f) => <FindingRow key={f.id} f={f} />)}
          </div>
        )}

        {tab === 'attack-surface' && (
          <div className="sh-section">
            <div className="sh-section__head"><Scan size={16} strokeWidth={1.75} /><span>Attack Surface Inventory</span></div>
            <div className="sh-surface-grid">
              {attackSurface.map((a) => (
                <div key={a.area} className="sh-surface-card">
                  <div className="sh-surface-card__head">
                    <a.icon size={16} />
                    <span className="sh-surface-card__name">{a.area}</span>
                    <span className={`sh-risk sh-risk--${a.risk}`}>{a.risk}</span>
                  </div>
                  <ul className="sh-surface-card__items">
                    {a.items.map((item) => <li key={item}>{item}</li>)}
                  </ul>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'auth' && (
          <div className="sh-section">
            <div className="sh-section__head"><Key size={16} strokeWidth={1.75} /><span>Authentication Controls</span></div>
            <div className="sh-auth-grid">
              {authControls.map((c) => (
                <div key={c.name} className="sh-auth-card">
                  <div className="sh-auth-card__icon"><c.icon size={16} /></div>
                  <div className="sh-auth-card__info">
                    <span className="sh-auth-card__name">{c.name}</span>
                    <span className="sh-auth-card__desc">{c.desc}</span>
                  </div>
                  <SDot status={c.status as 'pass' | 'warn' | 'fail'} />
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'rls' && (
          <div className="sh-section">
            <div className="sh-section__head"><Database size={16} strokeWidth={1.75} /><span>RLS & Authorization</span></div>
            <div className="sh-rls-grid">
              {rlsSummary.map((r) => (
                <div key={r.metric} className="sh-rls-card">
                  <SDot status={r.status as 'pass' | 'warn' | 'fail'} />
                  <span className="sh-rls-card__metric">{r.metric}</span>
                  <span className="sh-rls-card__value">{r.value}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'frontend' && (
          <div className="sh-section">
            <div className="sh-section__head"><Code size={16} strokeWidth={1.75} /><span>Frontend Security</span></div>
            <div className="sh-auth-grid">
              {frontendControls.map((c) => (
                <div key={c.name} className="sh-auth-card">
                  <div className="sh-auth-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><CheckCircle2 size={16} /></div>
                  <div className="sh-auth-card__info">
                    <span className="sh-auth-card__name">{c.name}</span>
                    <span className="sh-auth-card__desc">{c.desc}</span>
                  </div>
                  <SDot status="pass" />
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
