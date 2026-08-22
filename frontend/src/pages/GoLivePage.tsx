import { useState } from 'react';
import {
  CheckCircle2, Rocket, Database,
  PlayCircle, Flag, RefreshCcw, Users, Activity,
} from 'lucide-react';
import './go-live.css';

function Chk({ checked }: { checked: boolean }) {
  return checked
    ? <CheckCircle2 size={14} className="gl-icon-ok" />
    : <span className="gl-chk-empty" />;
}

/* ═══════════════ Main Page ═══════════════ */
export default function GoLivePage() {
  const [tab, setTab] = useState<'overview' | 'authorization' | 'migration' | 'verification' | 'rollback' | 'monitoring'>('overview');

  const authorizations = [
    { role: 'Hospital IT Director', name: '', date: '', status: false },
    { role: 'Technical Lead', name: '', date: '', status: false },
    { role: 'Security Officer', name: '', date: '', status: false },
    { role: 'Clinical Director', name: '', date: '', status: false },
    { role: 'Finance Director', name: '', date: '', status: false },
  ];

  const snapshotSteps = [
    { step: 'Release version tag', cmd: 'git tag -a v1.0.0-pilot', status: false },
    { step: 'Database backup (pg_dump)', cmd: 'pg_dump -Fc ...', status: false },
    { step: 'Configuration backup', cmd: 'cp .env .env.backup', status: false },
    { step: 'Migration snapshot', cmd: 'php artisan migrate:status', status: false },
    { step: 'Rollback reference', cmd: 'echo "Rollback to: ..."', status: false },
  ];

  const migrationSteps = [
    { step: 'Organization / Facility', status: false },
    { step: 'Departments / Wards / Rooms / Beds', status: false },
    { step: 'Staff / Roles / Permissions', status: false },
    { step: 'Services / Medications / Lab Tests', status: false },
    { step: 'Patients (with MRN mapping)', status: false },
    { step: 'Encounters / Diagnoses', status: false },
    { step: 'Prescriptions / Medications', status: false },
    { step: 'Lab Orders / Results', status: false },
    { step: 'Radiology Orders / Studies', status: false },
    { step: 'Appointments / Follow-ups', status: false },
    { step: 'Invoices / Payments', status: false },
    { step: 'Inventory / Stock', status: false },
    { step: 'Documents', status: false },
    { step: 'Audit Trail', status: false },
  ];

  const validationChecks = [
    { check: 'Record counts match source', status: false },
    { check: 'Patient search works', status: false },
    { check: 'MRN lookup works', status: false },
    { check: 'Encounter history intact', status: false },
    { check: 'Financial balances correct', status: false },
    { check: 'Lab results accessible', status: false },
    { check: 'Radiology studies accessible', status: false },
    { check: 'Prescriptions viewable', status: false },
    { check: 'Documents accessible', status: false },
    { check: 'Audit trail complete', status: false },
  ];

  const goLiveChecks = [
    { check: 'Login works', status: false },
    { check: 'Patient search works', status: false },
    { check: 'Appointment booking works', status: false },
    { check: 'Clinical workflow works', status: false },
    { check: 'Pharmacy dispensing works', status: false },
    { check: 'Lab workflow works', status: false },
    { check: 'Radiology workflow works', status: false },
    { check: 'Billing works', status: false },
    { check: 'Patient portal works', status: false },
    { check: 'No critical errors in logs', status: false },
  ];

  const rollbackSteps = [
    { step: 'Stop application traffic', time: '1 min' },
    { step: 'Restore database from backup', time: '5-15 min' },
    { step: 'Redeploy previous version', time: '2-5 min' },
    { step: 'Clear caches', time: '1 min' },
    { step: 'Verify health endpoints', time: '1 min' },
    { step: 'Verify data integrity', time: '5 min' },
    { step: 'Notify hospital IT team', time: '1 min' },
  ];

  const monitoringMetrics = [
    { metric: 'Application errors', freq: 'Every 5 min', threshold: '0 critical' },
    { metric: 'API latency (p95)', freq: 'Every 5 min', threshold: '< 500ms' },
    { metric: 'Database connections', freq: 'Every 5 min', threshold: '< 80% pool' },
    { metric: 'Queue depth', freq: 'Every 5 min', threshold: '< 100 jobs' },
    { metric: 'Auth failures', freq: 'Every 5 min', threshold: '< 10/min' },
    { metric: 'Disk usage', freq: 'Every 30 min', threshold: '< 80%' },
    { metric: 'Memory usage', freq: 'Every 30 min', threshold: '< 80%' },
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Go-Live Status' },
    { key: 'authorization' as const, label: 'Authorization' },
    { key: 'migration' as const, label: 'Data Migration' },
    { key: 'verification' as const, label: 'Verification' },
    { key: 'rollback' as const, label: 'Rollback' },
    { key: 'monitoring' as const, label: 'Monitoring' },
  ];

  return (
    <div className="gl-page">
      <div className="gl-header">
        <div className="gl-header__left">
          <h1 className="gl-title">Production Go-Live</h1>
          <p className="gl-subtitle">First controlled hospital production deployment — requires explicit authorization</p>
        </div>
        <div className="gl-header__right">
          <div className="gl-verdict-pill gl-verdict-pill--blocked">
            <Flag size={14} />
            <span>AUTHORIZATION REQUIRED</span>
          </div>
        </div>
      </div>

      <div className="gl-census">
        <div className="gl-census-card">
          <div className="gl-census-card__icon" style={{ background: '#fef2f2', color: '#dc2626' }}><Flag size={18} /></div>
          <div className="gl-census-card__info">
            <span className="gl-census-card__value">BLOCKED</span>
            <span className="gl-census-card__label">Go-Live Status</span>
          </div>
        </div>
        <div className="gl-census-card">
          <div className="gl-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><Users size={18} /></div>
          <div className="gl-census-card__info">
            <span className="gl-census-card__value">0/5</span>
            <span className="gl-census-card__label">Authorizations</span>
          </div>
        </div>
        <div className="gl-census-card">
          <div className="gl-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><Database size={18} /></div>
          <div className="gl-census-card__info">
            <span className="gl-census-card__value">0/5</span>
            <span className="gl-census-card__label">Snapshots</span>
          </div>
        </div>
        <div className="gl-census-card">
          <div className="gl-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><PlayCircle size={18} /></div>
          <div className="gl-census-card__info">
            <span className="gl-census-card__value">0/10</span>
            <span className="gl-census-card__label">Verification</span>
          </div>
        </div>
      </div>

      <div className="gl-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`gl-tab ${tab === t.key ? 'gl-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="gl-content">
        {tab === 'overview' && (
          <div className="gl-section">
            <div className="gl-section__head"><Rocket size={16} /><span>Go-Live Status</span></div>
            <div className="gl-verdict-card gl-verdict-card--blocked">
              <div className="gl-verdict-card__header"><Flag size={24} /><h3>AUTHORIZATION REQUIRED</h3></div>
              <p>SWASTHYA is technically ready for production go-live. However, actual deployment requires explicit authorization from the hospital IT director, technical lead, security officer, clinical director, and finance director. No deployment will occur without all required signatures.</p>
            </div>
            <h4 className="gl-subhead">Critical Rules</h4>
            <div className="gl-rules">
              <div className="gl-rule gl-rule--warn">No deployment without explicit authorization from all required signatories</div>
              <div className="gl-rule gl-rule--warn">No nationwide rollout — this is the FIRST controlled hospital go-live</div>
              <div className="gl-rule gl-rule--warn">No hidden failures — if migration fails, STOP and report</div>
              <div className="gl-rule gl-rule--warn">Rollback must be tested before go-live</div>
            </div>
          </div>
        )}

        {tab === 'authorization' && (
          <div className="gl-section">
            <div className="gl-section__head"><Users size={16} /><span>Go-Live Authorization</span></div>
            <div className="gl-table-wrap">
              <div className="gl-table gl-table--head"><span>Role</span><span>Name</span><span>Date</span><span>Signed</span></div>
              {authorizations.map((a) => (
                <div key={a.role} className="gl-table">
                  <span className="gl-bold">{a.role}</span>
                  <span className="gl-muted">{a.name || '[Pending]'}</span>
                  <span className="gl-muted">{a.date || '[Pending]'}</span>
                  <Chk checked={a.status} />
                </div>
              ))}
            </div>
            <h4 className="gl-subhead">Production Snapshot</h4>
            <div className="gl-table-wrap">
              <div className="gl-table gl-table--head"><span>Step</span><span>Command</span><span>Status</span></div>
              {snapshotSteps.map((s) => (
                <div key={s.step} className="gl-table">
                  <span className="gl-bold">{s.step}</span>
                  <span className="gl-mono">{s.cmd}</span>
                  <Chk checked={s.status} />
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'migration' && (
          <div className="gl-section">
            <div className="gl-section__head"><Database size={16} /><span>Data Migration</span></div>
            <h4 className="gl-subhead">Migration Sequence</h4>
            <div className="gl-checklist">
              {migrationSteps.map((m) => (
                <div key={m.step} className="gl-check-item">
                  <Chk checked={m.status} />
                  <span>{m.step}</span>
                </div>
              ))}
            </div>
            <h4 className="gl-subhead">Post-Migration Validation</h4>
            <div className="gl-checklist">
              {validationChecks.map((v) => (
                <div key={v.check} className="gl-check-item">
                  <Chk checked={v.status} />
                  <span>{v.check}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'verification' && (
          <div className="gl-section">
            <div className="gl-section__head"><CheckCircle2 size={16} /><span>Go-Live Verification</span></div>
            <div className="gl-checklist">
              {goLiveChecks.map((c) => (
                <div key={c.check} className="gl-check-item">
                  <Chk checked={c.status} />
                  <span>{c.check}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'rollback' && (
          <div className="gl-section">
            <div className="gl-section__head"><RefreshCcw size={16} /><span>Rollback Procedure</span></div>
            <div className="gl-table-wrap">
              <div className="gl-table gl-table--head"><span>Step</span><span>Action</span><span>Time</span></div>
              {rollbackSteps.map((r, i) => (
                <div key={r.step} className="gl-table">
                  <span className="gl-bold">{i + 1}</span>
                  <span>{r.step}</span>
                  <span className="gl-mono">{r.time}</span>
                </div>
              ))}
            </div>
            <div className="gl-total-row">Total rollback time: 15-30 minutes</div>
          </div>
        )}

        {tab === 'monitoring' && (
          <div className="gl-section">
            <div className="gl-section__head"><Activity size={16} /><span>Go-Live Monitoring</span></div>
            <h4 className="gl-subhead">High-Frequency Monitoring (First 24 hours)</h4>
            <div className="gl-table-wrap">
              <div className="gl-table gl-table--head"><span>Metric</span><span>Frequency</span><span>Threshold</span></div>
              {monitoringMetrics.map((m) => (
                <div key={m.metric} className="gl-table">
                  <span className="gl-bold">{m.metric}</span>
                  <span>{m.freq}</span>
                  <span className="gl-mono">{m.threshold}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
