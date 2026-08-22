import { useState } from 'react';
import {
  CheckCircle2, AlertTriangle, Clock, Rocket, Shield,
  Server, Lock, PlayCircle, Flag, Bell,
} from 'lucide-react';
import './pilot-launch.css';

function Chk({ checked }: { checked: boolean }) {
  return checked
    ? <CheckCircle2 size={14} className="pl-icon-ok" />
    : <span className="pl-chk-empty" />;
}

/* ═══════════════ Main Page ═══════════════ */
export default function PilotLaunchPage() {
  const [tab, setTab] = useState<'overview' | 'env' | 'config' | 'smoke' | 'incident' | 'verdict'>('overview');

  const envChecklist = [
    { item: 'Domain configured', done: false },
    { item: 'HTTPS/TLS certificate installed', done: false },
    { item: 'DNS records pointing to server', done: false },
    { item: 'Application server provisioned', done: false },
    { item: 'PostgreSQL 15+ with RLS', done: false },
    { item: 'Redis configured (cache/queue/session)', done: false },
    { item: 'Object storage provisioned', done: false },
    { item: 'WebSocket server configured', done: false },
    { item: 'Backup system active (daily + WAL)', done: false },
    { item: 'Monitoring dashboards live', done: false },
    { item: 'Alerting configured (PagerDuty/Slack)', done: false },
    { item: 'Structured logging active', done: false },
  ];

  const securityChecklist = [
    { item: 'APP_DEBUG=false', done: false },
    { item: 'APP_KEY generated (32 bytes)', done: false },
    { item: 'HTTPS enforced (HSTS)', done: false },
    { item: 'CORS allowlist = pilot domain only', done: false },
    { item: 'CSP headers configured', done: false },
    { item: 'Cookie security (secure, httponly, samesite)', done: false },
    { item: 'Rate limits active (auth: 5/min, API: 60/min)', done: false },
    { item: 'MFA enabled for admin accounts', done: false },
    { item: 'RLS enabled on all PHI tables', done: false },
    { item: 'Audit trail enabled', done: false },
    { item: 'Secrets in production vault', done: false },
    { item: 'No source maps in build', done: false },
  ];

  const smokeTests = [
    { workflow: 'Patient Registration', status: 'pending' as const },
    { workflow: 'Appointment Booking', status: 'pending' as const },
    { workflow: 'Encounter Start', status: 'pending' as const },
    { workflow: 'Diagnosis Entry', status: 'pending' as const },
    { workflow: 'Prescription', status: 'pending' as const },
    { workflow: 'Lab Order', status: 'pending' as const },
    { workflow: 'Radiology Order', status: 'pending' as const },
    { workflow: 'Pharmacy Dispense', status: 'pending' as const },
    { workflow: 'Billing Invoice', status: 'pending' as const },
    { workflow: 'Payment', status: 'pending' as const },
    { workflow: 'IPD Admission', status: 'pending' as const },
    { workflow: 'Discharge', status: 'pending' as const },
    { workflow: 'Patient Portal Login', status: 'pending' as const },
    { workflow: 'Cross-Tenant Isolation', status: 'pending' as const },
  ];

  const goCriteria = [
    'Hospital has formally authorized pilot deployment',
    'Pilot environment provisioned and verified',
    'Security configuration complete',
    'Hospital configuration complete',
    'Synthetic smoke test passed',
    'Monitoring and alerting active',
    'Rollback procedure tested',
    'Hospital IT team trained',
    'On-call support established',
  ];

  const noGoCriteria = [
    'No formal hospital authorization',
    'Critical security vulnerability unresolved',
    'Database backup not verified',
    'Monitoring not active',
    'Rollback procedure not tested',
    'Hospital IT team not trained',
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Pilot Overview' },
    { key: 'env' as const, label: 'Environment' },
    { key: 'config' as const, label: 'Security Config' },
    { key: 'smoke' as const, label: 'Smoke Test' },
    { key: 'incident' as const, label: 'Incident Process' },
    { key: 'verdict' as const, label: 'Go / No-Go' },
  ];

  return (
    <div className="pl-page">
      <div className="pl-header">
        <div className="pl-header__left">
          <h1 className="pl-title">Pilot Launch</h1>
          <p className="pl-subtitle">Controlled hospital pilot — requires explicit human authorization</p>
        </div>
        <div className="pl-header__right">
          <div className="pl-verdict-pill pl-verdict-pill--pending">
            <Clock size={14} />
            <span>AUTHORIZATION REQUIRED</span>
          </div>
        </div>
      </div>

      <div className="pl-census">
        <div className="pl-census-card">
          <div className="pl-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><Clock size={18} /></div>
          <div className="pl-census-card__info">
            <span className="pl-census-card__value">0/12</span>
            <span className="pl-census-card__label">Env Ready</span>
          </div>
        </div>
        <div className="pl-census-card">
          <div className="pl-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><Shield size={18} /></div>
          <div className="pl-census-card__info">
            <span className="pl-census-card__value">0/12</span>
            <span className="pl-census-card__label">Security Config</span>
          </div>
        </div>
        <div className="pl-census-card">
          <div className="pl-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><PlayCircle size={18} /></div>
          <div className="pl-census-card__info">
            <span className="pl-census-card__value">0/14</span>
            <span className="pl-census-card__label">Smoke Tests</span>
          </div>
        </div>
        <div className="pl-census-card">
          <div className="pl-census-card__icon" style={{ background: '#fef2f2', color: '#dc2626' }}><Flag size={18} /></div>
          <div className="pl-census-card__info">
            <span className="pl-census-card__value">BLOCKED</span>
            <span className="pl-census-card__label">Launch Status</span>
          </div>
        </div>
      </div>

      <div className="pl-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`pl-tab ${tab === t.key ? 'pl-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="pl-content">
        {tab === 'overview' && (
          <div className="pl-section">
            <div className="pl-section__head"><Rocket size={16} /><span>Pilot Launch Status</span></div>
            <div className="pl-verdict-card pl-verdict-card--blocked">
              <div className="pl-verdict-card__header"><Flag size={24} /><h3>AUTHORIZATION REQUIRED</h3></div>
              <p>SWASTHYA is technically ready for pilot deployment (Phase 124: CONDITIONALLY READY). However, actual deployment requires explicit human authorization from the hospital IT director and project lead. No deployment will occur without this authorization.</p>
            </div>
            <h4 className="pl-subhead">Release Baseline</h4>
            <div className="pl-stats-grid">
              <div className="pl-stat"><span className="pl-stat__val">ec6f3d2</span><span className="pl-stat__lbl">Release Commit</span></div>
              <div className="pl-stat"><span className="pl-stat__val">main</span><span className="pl-stat__lbl">Branch</span></div>
              <div className="pl-stat"><span className="pl-stat__val">78/78</span><span className="pl-stat__lbl">Tests</span></div>
              <div className="pl-stat"><span className="pl-stat__val">0</span><span className="pl-stat__lbl">TS Errors</span></div>
              <div className="pl-stat"><span className="pl-stat__val">0</span><span className="pl-stat__lbl">Critical</span></div>
              <div className="pl-stat"><span className="pl-stat__val">144s</span><span className="pl-stat__lbl">DR Restore</span></div>
            </div>
            <h4 className="pl-subhead">Pilot Rules</h4>
            <div className="pl-rules">
              <div className="pl-rule pl-rule--warn">Do NOT deploy automatically — every step requires explicit human authorization</div>
              <div className="pl-rule pl-rule--warn">Do NOT onboard real PHI without formal hospital authorization</div>
              <div className="pl-rule pl-rule--warn">Do NOT reuse staging as production — dedicated pilot environment required</div>
              <div className="pl-rule pl-rule--warn">Do NOT introduce unrelated features during pilot — only blocker/critical/security fixes</div>
            </div>
          </div>
        )}

        {tab === 'env' && (
          <div className="pl-section">
            <div className="pl-section__head"><Server size={16} /><span>Pilot Environment Checklist</span></div>
            <div className="pl-checklist">
              {envChecklist.map((c) => (
                <div key={c.item} className="pl-check-item">
                  <Chk checked={c.done} />
                  <span className="pl-check-item__text">{c.item}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'config' && (
          <div className="pl-section">
            <div className="pl-section__head"><Lock size={16} /><span>Security Configuration</span></div>
            <div className="pl-checklist">
              {securityChecklist.map((c) => (
                <div key={c.item} className="pl-check-item">
                  <Chk checked={c.done} />
                  <span className="pl-check-item__text">{c.item}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'smoke' && (
          <div className="pl-section">
            <div className="pl-section__head"><PlayCircle size={16} /><span>Synthetic Smoke Test</span></div>
            <div className="pl-table-wrap">
              <div className="pl-table pl-table--head"><span>Workflow</span><span>Status</span></div>
              {smokeTests.map((s) => (
                <div key={s.workflow} className="pl-table">
                  <span className="pl-bold">{s.workflow}</span>
                  <span className="pl-badge pl-badge--pending">Pending</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'incident' && (
          <div className="pl-section">
            <div className="pl-section__head"><Bell size={16} /><span>Pilot Incident Process</span></div>
            <div className="pl-table-wrap">
              <div className="pl-table pl-table--head"><span>Severity</span><span>Response Time</span><span>Escalation</span></div>
              {[
                { sev: 'P1 — Critical (platform down)', time: '< 15 min', esc: 'Immediate — all hands' },
                { sev: 'P2 — High (major feature broken)', time: '< 1 hour', esc: 'Engineering lead' },
                { sev: 'P3 — Medium (non-critical)', time: '< 4 hours', esc: 'Support team' },
                { sev: 'P4 — Low (minor/cosmetic)', time: '< 24 hours', esc: 'Normal queue' },
              ].map((r) => (
                <div key={r.sev} className="pl-table">
                  <span className="pl-bold">{r.sev}</span>
                  <span>{r.time}</span>
                  <span className="pl-muted">{r.esc}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'verdict' && (
          <div className="pl-section">
            <div className="pl-section__head"><Flag size={16} /><span>Go / No-Go Decision</span></div>
            <h4 className="pl-subhead">GO Criteria (all must be true)</h4>
            <div className="pl-checklist">
              {goCriteria.map((c) => (
                <div key={c} className="pl-check-item">
                  <Chk checked={false} />
                  <span className="pl-check-item__text">{c}</span>
                </div>
              ))}
            </div>
            <h4 className="pl-subhead">NO-GO Criteria (any blocks deployment)</h4>
            <div className="pl-checklist">
              {noGoCriteria.map((c) => (
                <div key={c} className="pl-check-item pl-check-item--nogo">
                  <AlertTriangle size={14} className="pl-icon-warn" />
                  <span className="pl-check-item__text">{c}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
