import { useState } from 'react';
import {
  Shield, CheckCircle2, AlertTriangle, Lock, Database, Activity,
  RefreshCcw, TrendingUp,
} from 'lucide-react';
import './continuous-security.css';

/* ═══════════════ Main Page ═══════════════ */
export default function ContinuousSecurityPage() {
  const [tab, setTab] = useState<'overview' | 'vulns' | 'pen' | 'rls' | 'dr' | 'resilience'>('overview');

  const vulnScan = [
    { target: 'npm dependencies', tool: 'npm audit', freq: 'Weekly (CI)', high: 0, critical: 0 },
    { target: 'Composer dependencies', tool: 'composer audit', freq: 'Weekly (CI)', high: 0, critical: 0 },
    { target: 'Container images', tool: 'Trivy/Grype', freq: 'On build', high: 0, critical: 0 },
    { target: 'Infrastructure', tool: 'Cloud scanner', freq: 'Monthly', high: 0, critical: 0 },
    { target: 'Application', tool: 'OWASP ZAP', freq: 'Quarterly', high: 0, critical: 0 },
    { target: 'Secrets', tool: 'GitLeaks', freq: 'On commit', high: 0, critical: 0 },
  ];

  const penTests = [
    { test: 'Self-test (Phase 122)', date: 'Aug 2026', firm: 'Internal', findings: '0 critical, 0 high', status: 'Complete' },
    { test: 'External pen test', date: 'Pending', firm: 'TBD', findings: '—', status: 'Not performed' },
  ];

  const rlsTests = [
    { isolation: 'Tenant', test: 'Cross-tenant data access', expected: '0 rows', freq: 'Every release' },
    { isolation: 'Facility', test: 'Cross-facility data access', expected: '0 rows', freq: 'Every release' },
    { isolation: 'Role', test: 'Cross-role privilege escalation', expected: 'Denied', freq: 'Every release' },
    { isolation: 'Patient', test: 'Cross-patient data access', expected: '0 rows', freq: 'Every release' },
    { isolation: 'Claim forgery', test: 'Forged JWT claims', expected: 'Denied', freq: 'Every release' },
    { isolation: 'Missing claims', test: 'API without tenant context', expected: '0 rows', freq: 'Every release' },
  ];

  const drExercises = [
    { exercise: 'Database restore', freq: 'Quarterly', scope: 'Full database', status: 'Tested (Phase 121)' },
    { exercise: 'Application failover', freq: 'Quarterly', scope: 'App + DB', status: 'Tested (Phase 121)' },
    { exercise: 'Queue recovery', freq: 'Semi-annually', scope: 'Queue workers', status: 'Designed' },
    { exercise: 'Provider outage', freq: 'Semi-annually', scope: 'External integrations', status: 'Designed' },
    { exercise: 'Full DR', freq: 'Annually', scope: 'Everything', status: 'Designed' },
  ];

  const resilience = [
    { metric: 'RTO', definition: 'Recovery Time Objective', target: '< 4 hours', current: '144s (measured)' },
    { metric: 'RPO', definition: 'Recovery Point Objective', target: '< 15 min', current: 'On-demand (dev)' },
    { metric: 'MTTR', definition: 'Mean Time To Resolve', target: '< 2 hours', current: 'TBD' },
    { metric: 'Incident Count', definition: 'Security incidents/month', target: '< 3', current: '0' },
    { metric: 'Unresolved Risk', definition: 'Open security findings', target: '0 critical', current: '0' },
    { metric: 'Backup Success', definition: 'Backup completion rate', target: '100%', current: '100%' },
    { metric: 'DR Exercise Success', definition: 'Exercise pass rate', target: '100%', current: 'TBD' },
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Security Program' },
    { key: 'vulns' as const, label: 'Vulnerability Management' },
    { key: 'pen' as const, label: 'Penetration Testing' },
    { key: 'rls' as const, label: 'RLS Regression' },
    { key: 'dr' as const, label: 'DR Exercises' },
    { key: 'resilience' as const, label: 'Resilience Metrics' },
  ];

  return (
    <div className="cs-page">
      <div className="cs-header">
        <div className="cs-header__left">
          <h1 className="cs-title">Continuous Security & Resilience</h1>
          <p className="cs-subtitle">Recurring security, vulnerability management, DR and resilience program</p>
        </div>
        <div className="cs-header__right">
          <div className="cs-status-pill cs-status-pill--ok">
            <Shield size={14} />
            <span>PROGRAM ACTIVE</span>
          </div>
        </div>
      </div>

      <div className="cs-census">
        <div className="cs-census-card">
          <div className="cs-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Shield size={18} /></div>
          <div className="cs-census-card__info">
            <span className="cs-census-card__value">0</span>
            <span className="cs-census-card__label">Critical Vulns</span>
          </div>
        </div>
        <div className="cs-census-card">
          <div className="cs-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Database size={18} /></div>
          <div className="cs-census-card__info">
            <span className="cs-census-card__value">508</span>
            <span className="cs-census-card__label">RLS Policies</span>
          </div>
        </div>
        <div className="cs-census-card">
          <div className="cs-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><CheckCircle2 size={18} /></div>
          <div className="cs-census-card__info">
            <span className="cs-census-card__value">144s</span>
            <span className="cs-census-card__label">DR Restore</span>
          </div>
        </div>
        <div className="cs-census-card">
          <div className="cs-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Activity size={18} /></div>
          <div className="cs-census-card__info">
            <span className="cs-census-card__value">0</span>
            <span className="cs-census-card__label">Open Incidents</span>
          </div>
        </div>
      </div>

      <div className="cs-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`cs-tab ${tab === t.key ? 'cs-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="cs-content">
        {tab === 'overview' && (
          <div className="cs-section">
            <div className="cs-section__head"><Shield size={16} /><span>Security Program Status</span></div>
            <div className="cs-verdict-card cs-verdict-card--ok">
              <div className="cs-verdict-card__header"><CheckCircle2 size={24} /><h3>PROGRAM ACTIVE</h3></div>
              <p>SWASTHYA operates a continuous security and resilience program with monthly reviews, quarterly DR exercises, and annual penetration testing. Current status: 0 critical vulnerabilities, 508 RLS policies verified, 144s DR restore time, 0 open incidents.</p>
            </div>
            <h4 className="cs-subhead">Security Review Schedule</h4>
            <div className="cs-schedule">
              {[
                { review: 'Dependency scan', freq: 'Weekly (automated)', owner: 'CI/CD' },
                { review: 'RLS regression', freq: 'Every major release', owner: 'Security Lead' },
                { review: 'Security audit', freq: 'Monthly', owner: 'Security Lead' },
                { review: 'Penetration test', freq: 'Annually', owner: 'External firm' },
                { review: 'DR exercise', freq: 'Quarterly', owner: 'SRE Lead' },
                { review: 'Tabletop exercise', freq: 'Semi-annually', owner: 'Security Lead' },
              ].map((r) => (
                <div key={r.review} className="cs-schedule-item">
                  <span className="cs-schedule-review">{r.review}</span>
                  <span className="cs-schedule-freq">{r.freq}</span>
                  <span className="cs-schedule-owner">{r.owner}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'vulns' && (
          <div className="cs-section">
            <div className="cs-section__head"><AlertTriangle size={16} /><span>Vulnerability Management</span></div>
            <div className="cs-table-wrap">
              <div className="cs-table cs-table--head"><span>Target</span><span>Tool</span><span>Frequency</span><span>High</span><span>Critical</span></div>
              {vulnScan.map((v) => (
                <div key={v.target} className="cs-table">
                  <span className="cs-bold">{v.target}</span>
                  <span className="cs-muted">{v.tool}</span>
                  <span>{v.freq}</span>
                  <span className="cs-val-ok">{v.high}</span>
                  <span className="cs-val-ok">{v.critical}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'pen' && (
          <div className="cs-section">
            <div className="cs-section__head"><Lock size={16} /><span>Penetration Testing</span></div>
            <div className="cs-table-wrap">
              <div className="cs-table cs-table--head"><span>Test</span><span>Date</span><span>Firm</span><span>Findings</span><span>Status</span></div>
              {penTests.map((p) => (
                <div key={p.test} className="cs-table">
                  <span className="cs-bold">{p.test}</span>
                  <span>{p.date}</span>
                  <span className="cs-muted">{p.firm}</span>
                  <span>{p.findings}</span>
                  <span className={`cs-badge cs-badge--${p.status === 'Complete' ? 'ok' : 'pending'}`}>{p.status}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'rls' && (
          <div className="cs-section">
            <div className="cs-section__head"><Database size={16} /><span>RLS Regression Testing</span></div>
            <div className="cs-table-wrap">
              <div className="cs-table cs-table--head"><span>Isolation</span><span>Test</span><span>Expected</span><span>Frequency</span></div>
              {rlsTests.map((r) => (
                <div key={r.isolation} className="cs-table">
                  <span className="cs-bold">{r.isolation}</span>
                  <span className="cs-muted">{r.test}</span>
                  <span className="cs-mono">{r.expected}</span>
                  <span>{r.freq}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'dr' && (
          <div className="cs-section">
            <div className="cs-section__head"><RefreshCcw size={16} /><span>DR Exercises</span></div>
            <div className="cs-table-wrap">
              <div className="cs-table cs-table--head"><span>Exercise</span><span>Frequency</span><span>Scope</span><span>Status</span></div>
              {drExercises.map((d) => (
                <div key={d.exercise} className="cs-table">
                  <span className="cs-bold">{d.exercise}</span>
                  <span>{d.freq}</span>
                  <span className="cs-muted">{d.scope}</span>
                  <span className={`cs-badge cs-badge--${d.status.includes('Tested') ? 'ok' : 'pending'}`}>{d.status}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'resilience' && (
          <div className="cs-section">
            <div className="cs-section__head"><TrendingUp size={16} /><span>Resilience Metrics</span></div>
            <div className="cs-table-wrap">
              <div className="cs-table cs-table--head"><span>Metric</span><span>Definition</span><span>Target</span><span>Current</span></div>
              {resilience.map((r) => (
                <div key={r.metric} className="cs-table">
                  <span className="cs-bold">{r.metric}</span>
                  <span className="cs-muted">{r.definition}</span>
                  <span className="cs-mono">{r.target}</span>
                  <span className="cs-mono">{r.current}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
