import { useState } from 'react';
import {
  CheckCircle2, AlertTriangle, Clock, Activity, Database,
  Users, Flag,
} from 'lucide-react';
import './hypercare.css';

function Chk({ checked }: { checked: boolean }) {
  return checked
    ? <CheckCircle2 size={14} className="hc-icon-ok" />
    : <span className="hc-chk-empty" />;
}

/* ═══════════════ Main Page ═══════════════ */
export default function HypercarePage() {
  const [tab, setTab] = useState<'overview' | 'health' | 'incidents' | 'feedback' | 'data' | 'exit'>('overview');

  const healthMetrics = [
    { metric: 'Uptime', target: '≥ 99.9%', actual: '[Pending deployment]', status: 'pending' },
    { metric: 'Error Rate', target: '< 0.1%', actual: '[Pending deployment]', status: 'pending' },
    { metric: 'API Latency (p95)', target: '< 500ms', actual: '[Pending deployment]', status: 'pending' },
    { metric: 'API Latency (p99)', target: '< 1s', actual: '[Pending deployment]', status: 'pending' },
    { metric: 'DB Connections', target: '< 80% pool', actual: '[Pending deployment]', status: 'pending' },
    { metric: 'Queue Depth', target: '< 50 jobs', actual: '[Pending deployment]', status: 'pending' },
    { metric: 'Storage Usage', target: '< 70%', actual: '[Pending deployment]', status: 'pending' },
    { metric: 'Auth Failure Rate', target: '< 5/min', actual: '[Pending deployment]', status: 'pending' },
  ];

  const incidents = [
    { sev: 'CRITICAL', count: 0, resolved: 0 },
    { sev: 'HIGH', count: 0, resolved: 0 },
    { sev: 'MEDIUM', count: 0, resolved: 0 },
    { sev: 'LOW', count: 0, resolved: 0 },
    { sev: 'ENHANCEMENT', count: 0, backlogged: 0 },
  ];

  const feedbackCategories = [
    { cat: 'BUG', count: 0, resolved: 0 },
    { cat: 'UX ISSUE', count: 0, addressed: 0 },
    { cat: 'TRAINING ISSUE', count: 0, resolved: 0 },
    { cat: 'FEATURE REQUEST', count: 0, backlogged: 0 },
  ];

  const dataQuality = [
    { check: 'Duplicate patients', expected: 'Zero', actual: '[Pending]', status: 'pending' },
    { check: 'Orphan encounters', expected: 'Zero', actual: '[Pending]', status: 'pending' },
    { check: 'Failed notifications', expected: 'Zero', actual: '[Pending]', status: 'pending' },
    { check: 'Billing mismatches', expected: 'Zero', actual: '[Pending]', status: 'pending' },
    { check: 'Inventory inconsistencies', expected: 'Zero', actual: '[Pending]', status: 'pending' },
    { check: 'Unresolved patient IDs', expected: 'Zero', actual: '[Pending]', status: 'pending' },
  ];

  const exitCriteria = [
    'No critical defects open',
    'No unresolved high-severity integrity/security defects',
    'Operational metrics stable for 7+ days',
    'Support process stable',
    'Hospital team confirms satisfaction',
    'Data quality verified',
    'Backup/restore tested',
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Hypercare Overview' },
    { key: 'health' as const, label: 'Production Health' },
    { key: 'incidents' as const, label: 'Incidents' },
    { key: 'feedback' as const, label: 'User Feedback' },
    { key: 'data' as const, label: 'Data Quality' },
    { key: 'exit' as const, label: 'Exit Criteria' },
  ];

  return (
    <div className="hc-page">
      <div className="hc-header">
        <div className="hc-header__left">
          <h1 className="hc-title">Hypercare & Stabilization</h1>
          <p className="hc-subtitle">Post-go-live monitoring, defect closure, and stabilization — 30-day minimum</p>
        </div>
        <div className="hc-header__right">
          <div className="hc-verdict-pill hc-verdict-pill--pending">
            <Clock size={14} />
            <span>AWAITING GO-LIVE</span>
          </div>
        </div>
      </div>

      <div className="hc-census">
        <div className="hc-census-card">
          <div className="hc-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><Clock size={18} /></div>
          <div className="hc-census-card__info">
            <span className="hc-census-card__value">Day 0</span>
            <span className="hc-census-card__label">Hypercare Day</span>
          </div>
        </div>
        <div className="hc-census-card">
          <div className="hc-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><CheckCircle2 size={18} /></div>
          <div className="hc-census-card__info">
            <span className="hc-census-card__value">0</span>
            <span className="hc-census-card__label">Open Critical</span>
          </div>
        </div>
        <div className="hc-census-card">
          <div className="hc-census-card__icon" style={{ background: '#f0f5ff', color: '#2563eb' }}><Activity size={18} /></div>
          <div className="hc-census-card__info">
            <span className="hc-census-card__value">0</span>
            <span className="hc-census-card__label">Total Incidents</span>
          </div>
        </div>
        <div className="hc-census-card">
          <div className="hc-census-card__icon" style={{ background: '#f0f5ff', color: '#2563eb' }}><Users size={18} /></div>
          <div className="hc-census-card__info">
            <span className="hc-census-card__value">0</span>
            <span className="hc-census-card__label">Feedback Items</span>
          </div>
        </div>
      </div>

      <div className="hc-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`hc-tab ${tab === t.key ? 'hc-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="hc-content">
        {tab === 'overview' && (
          <div className="hc-section">
            <div className="hc-section__head"><Flag size={16} /><span>Hypercare Status</span></div>
            <div className="hc-verdict-card hc-verdict-card--pending">
              <div className="hc-verdict-card__header"><Clock size={24} /><h3>AWAITING GO-LIVE</h3></div>
              <p>Hypercare begins only after Phase 126 GO-LIVE SUCCESSFUL. The hypercare period lasts a minimum of 30 days, with reviews at 30, 60, and 90 days. During hypercare, no major new modules are launched — only stabilization and defect closure.</p>
            </div>
            <h4 className="hc-subhead">Hypercare Rules</h4>
            <div className="hc-rules">
              <div className="hc-rule hc-rule--warn">No major new modules during hypercare — stabilization only</div>
              <div className="hc-rule hc-rule--warn">All fixes follow release process — branch, tests, review, CI, approval</div>
              <div className="hc-rule hc-rule--warn">No untested deployments — every change must have test coverage</div>
              <div className="hc-rule hc-rule--warn">No hidden issues — every incident is classified and tracked</div>
            </div>
            <h4 className="hc-subhead">Timeline</h4>
            <div className="hc-timeline">
              {[
                { day: '1-7', activity: 'Intensive monitoring, rapid fix cycle', review: 'Daily standup' },
                { day: '8-14', activity: 'Stabilization, user feedback collection', review: 'Daily standup' },
                { day: '15-21', activity: 'Performance review, data quality audit', review: 'Weekly review' },
                { day: '22-30', activity: 'Exit criteria assessment, formal report', review: '30-day review' },
              ].map((t) => (
                <div key={t.day} className="hc-timeline-item">
                  <span className="hc-timeline-day">Day {t.day}</span>
                  <span className="hc-timeline-activity">{t.activity}</span>
                  <span className="hc-timeline-review">{t.review}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'health' && (
          <div className="hc-section">
            <div className="hc-section__head"><Activity size={16} /><span>Production Health Metrics</span></div>
            <div className="hc-table-wrap">
              <div className="hc-table hc-table--head"><span>Metric</span><span>Target</span><span>Actual</span><span>Status</span></div>
              {healthMetrics.map((m) => (
                <div key={m.metric} className="hc-table">
                  <span className="hc-bold">{m.metric}</span>
                  <span className="hc-mono">{m.target}</span>
                  <span className="hc-muted">{m.actual}</span>
                  <span className="hc-badge hc-badge--pending">Pending</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'incidents' && (
          <div className="hc-section">
            <div className="hc-section__head"><AlertTriangle size={16} /><span>Incident Tracker</span></div>
            <div className="hc-table-wrap">
              <div className="hc-table hc-table--head"><span>Severity</span><span>Open</span><span>Resolved</span><span>Status</span></div>
              {incidents.map((i) => (
                <div key={i.sev} className="hc-table">
                  <span className="hc-bold">{i.sev}</span>
                  <span>{i.count}</span>
                  <span>{i.resolved}</span>
                  <span className="hc-badge hc-badge--ok">Clear</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'feedback' && (
          <div className="hc-section">
            <div className="hc-section__head"><Users size={16} /><span>User Feedback</span></div>
            <div className="hc-table-wrap">
              <div className="hc-table hc-table--head"><span>Category</span><span>Reported</span><span>Resolved</span><span>Status</span></div>
              {feedbackCategories.map((f) => (
                <div key={f.cat} className="hc-table">
                  <span className="hc-bold">{f.cat}</span>
                  <span>{f.count}</span>
                  <span>{f.resolved}</span>
                  <span className="hc-badge hc-badge--ok">Clear</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'data' && (
          <div className="hc-section">
            <div className="hc-section__head"><Database size={16} /><span>Data Quality</span></div>
            <div className="hc-table-wrap">
              <div className="hc-table hc-table--head"><span>Check</span><span>Expected</span><span>Actual</span><span>Status</span></div>
              {dataQuality.map((d) => (
                <div key={d.check} className="hc-table">
                  <span className="hc-bold">{d.check}</span>
                  <span>{d.expected}</span>
                  <span className="hc-muted">{d.actual}</span>
                  <span className="hc-badge hc-badge--pending">Pending</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'exit' && (
          <div className="hc-section">
            <div className="hc-section__head"><CheckCircle2 size={16} /><span>Exit Criteria</span></div>
            <div className="hc-checklist">
              {exitCriteria.map((c) => (
                <div key={c} className="hc-check-item">
                  <Chk checked={false} />
                  <span>{c}</span>
                </div>
              ))}
            </div>
            <div className="hc-verdict-card hc-verdict-card--pending" style={{ marginTop: 16 }}>
              <div className="hc-verdict-card__header"><Flag size={24} /><h3>HYPERCARE NOT ACTIVE</h3></div>
              <p>Hypercare begins only after GO-LIVE SUCCESSFUL. Exit criteria will be assessed at the 30-day review. Hypercare ends only when ALL exit criteria are met and the hospital team confirms satisfaction.</p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
