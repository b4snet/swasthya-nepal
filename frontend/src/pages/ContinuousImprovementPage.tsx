import { useState } from 'react';
import {
  CheckCircle2, Shield, Lock, TrendingUp, AlertTriangle, Flag,
  Clipboard, Activity, RefreshCw, Users,
} from 'lucide-react';
import './continuous-improvement.css';

type Tab = 'overview' | 'change-control' | 'security' | 'roadmap' | 'report';

export default function ContinuousImprovementPage() {
  const [tab, setTab] = useState<Tab>('overview');

  const changeControls = [
    { domain: 'Clinical (medication, diagnosis, triage)', reviewer: 'Clinical + Security', required: true },
    { domain: 'Security (RLS, auth, storage, API)', reviewer: 'Security + Engineering', required: true },
    { domain: 'Database (schema, migrations)', reviewer: 'Engineering + DBA', required: true },
    { domain: 'API (endpoints, contracts)', reviewer: 'Engineering + Product', required: true },
    { domain: 'Frontend (design system, navigation)', reviewer: 'Product', required: false },
    { domain: 'Integration (external systems)', reviewer: 'Engineering + Security', required: true },
    { domain: 'Financial (billing, payments)', reviewer: 'Finance + Security', required: true },
    { domain: 'Workflow (new modules, features)', reviewer: 'Product + Engineering', required: false },
  ];

  const securityCycle = [
    { step: 'SCAN', desc: 'Dependencies, RLS, auth, storage, API', icon: Shield },
    { step: 'ASSESS', desc: 'Classify findings by severity and exploitability', icon: AlertTriangle },
    { step: 'FIX', desc: 'Remediate with documented changes', icon: RefreshCw },
    { step: 'TEST', desc: 'Verify fix, run regression suite', icon: Activity },
    { step: 'DEPLOY', desc: 'Release through controlled pipeline', icon: TrendingUp },
    { step: 'MONITOR', desc: 'Observe production for regressions', icon: Activity },
    { step: 'RETEST', desc: 'Confirm fix holds under load', icon: CheckCircle2 },
  ];

  const roadmap = [
    { item: 'External penetration test', priority: 1, status: 'Required', category: 'Security' },
    { item: 'First hospital deployment', priority: 2, status: 'Requires authorization', category: 'Deployment' },
    { item: 'Production PITR configuration', priority: 3, status: 'Required', category: 'Infrastructure' },
    { item: 'External integration certification', priority: 4, status: 'Requires partners', category: 'Interop' },
    { item: 'Multi-hospital expansion', priority: 5, status: 'After first stable', category: 'Growth' },
    { item: 'Clinical documentation depth', priority: 6, status: 'Evidence-driven', category: 'Clinical' },
    { item: 'Advanced analytics', priority: 7, status: 'After operational data', category: 'Analytics' },
  ];

  const governanceReport = {
    status: 'CONDITIONALLY READY',
    hospitals: 0,
    facilities: 0,
    modules: 28,
    implemented: 25,
    partial: 3,
    uptime: 'N/A (pre-deployment)',
    incidents: 0,
    criticalDefects: 0,
    highDefects: 0,
    securityFindings: '0 critical, 0 high',
    drRestoreTime: '144s',
    loadTested: '1M patients',
    technicalDebt: 4,
    clinicalRisks: 4,
    operationalRisks: 4,
  };

  const tabs: { key: Tab; label: string }[] = [
    { key: 'overview', label: 'Governance Overview' },
    { key: 'change-control', label: 'Change Control' },
    { key: 'security', label: 'Security Loop' },
    { key: 'roadmap', label: 'Roadmap' },
    { key: 'report', label: 'Governance Report' },
  ];

  return (
    <div className="ci-page">
      <div className="ci-header">
        <div className="ci-header__left">
          <h1 className="ci-title">Continuous Production Governance</h1>
          <p className="ci-subtitle">Evidence-driven product evolution and clinical change control for SWASTHYA</p>
        </div>
        <div className="ci-header__right">
          <div className="ci-verdict-pill ci-verdict-pill--conditional">
            <Flag size={14} />
            <span>CONDITIONALLY READY</span>
          </div>
        </div>
      </div>

      <div className="ci-census">
        <div className="ci-census-card">
          <div className="ci-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><CheckCircle2 size={18} /></div>
          <div className="ci-census-card__info">
            <span className="ci-census-card__value">{governanceReport.implemented}/{governanceReport.modules}</span>
            <span className="ci-census-card__label">Features</span>
          </div>
        </div>
        <div className="ci-census-card">
          <div className="ci-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Shield size={18} /></div>
          <div className="ci-census-card__info">
            <span className="ci-census-card__value">{governanceReport.criticalDefects}</span>
            <span className="ci-census-card__label">Critical Defects</span>
          </div>
        </div>
        <div className="ci-census-card">
          <div className="ci-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Lock size={18} /></div>
          <div className="ci-census-card__info">
            <span className="ci-census-card__value">{governanceReport.securityFindings}</span>
            <span className="ci-census-card__label">Security</span>
          </div>
        </div>
        <div className="ci-census-card">
          <div className="ci-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Users size={18} /></div>
          <div className="ci-census-card__info">
            <span className="ci-census-card__value">{governanceReport.hospitals}</span>
            <span className="ci-census-card__label">Hospitals</span>
          </div>
        </div>
      </div>

      <div className="ci-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`ci-tab ${tab === t.key ? 'ci-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="ci-content">
        {tab === 'overview' && (
          <div className="ci-section">
            <div className="ci-section__head"><Flag size={16} /><span>Governance Framework</span></div>
            <div className="ci-principle-grid">
              <div className="ci-principle-card">
                <h4>Core Rule</h4>
                <p>No new feature is added without: documented problem, identified user, measurable benefit, security analysis, clinical-safety analysis, architectural impact, operational impact, testing strategy, and rollback strategy.</p>
              </div>
              <div className="ci-principle-card">
                <h4>Clinical Change Control</h4>
                <p>Any change affecting medication, diagnosis, triage, laboratory, radiology, blood bank, ICU, OT, oncology, discharge, patient identity, or billing requires clinical-safety review with failure-mode analysis.</p>
              </div>
              <div className="ci-principle-card">
                <h4>Security Change Control</h4>
                <p>Any change affecting RLS, permissions, authentication, storage, realtime, APIs, integrations, patient portal, financial data, or PHI requires security review. No feature may weaken tenant or facility isolation.</p>
              </div>
              <div className="ci-principle-card">
                <h4>Multi-Tenant Safety</h4>
                <p>Every new feature must prove tenant, facility, department, role, and patient scope. Forged identifiers, direct URLs, and API manipulation are tested for every new endpoint.</p>
              </div>
            </div>

            <h4 className="ci-subhead">Feature Request Governance</h4>
            <div className="ci-flow">
              {['PROBLEM', 'USER', 'BENEFIT', 'SECURITY', 'CLINICAL', 'ARCHITECTURE', 'OPERATIONS', 'TESTING', 'ROLLBACK'].map((step, i) => (
                <span key={step} className="ci-flow-step">
                  <span className="ci-flow-num">{i + 1}</span>
                  <span className="ci-flow-label">{step}</span>
                </span>
              ))}
            </div>
          </div>
        )}

        {tab === 'change-control' && (
          <div className="ci-section">
            <div className="ci-section__head"><Clipboard size={16} /><span>Change Control Board</span></div>
            <div className="ci-table-wrap">
              <div className="ci-table ci-table--head"><span>Domain</span><span>Review Required</span><span>Reviewers</span></div>
              {changeControls.map((cc) => (
                <div key={cc.domain} className="ci-table">
                  <span className="ci-bold">{cc.domain}</span>
                  <span>{cc.required ? <span className="ci-badge ci-badge--required">Required</span> : <span className="ci-badge ci-badge--optional">Recommended</span>}</span>
                  <span className="ci-muted">{cc.reviewer}</span>
                </div>
              ))}
            </div>

            <h4 className="ci-subhead">Release Pipeline</h4>
            <div className="ci-flow">
              {['PLAN', 'DEV', 'TEST', 'SECURITY', 'STAGING', 'UAT', 'APPROVE', 'PROD', 'MONITOR'].map((step, i) => (
                <span key={step} className="ci-flow-step">
                  <span className="ci-flow-num">{i + 1}</span>
                  <span className="ci-flow-label">{step}</span>
                </span>
              ))}
            </div>

            <h4 className="ci-subhead">Database Change Control</h4>
            <div className="ci-checklist">
              {['Backwards compatibility verified', 'Data migration planned', 'Rollback method defined', 'Index impact assessed', 'RLS impact reviewed', 'Production data tested', 'Reporting impact checked'].map((item) => (
                <div key={item} className="ci-checklist-item"><CheckCircle2 size={14} className="ci-icon-ok" /><span>{item}</span></div>
              ))}
            </div>
          </div>
        )}

        {tab === 'security' && (
          <div className="ci-section">
            <div className="ci-section__head"><Shield size={16} /><span>Continuous Security Loop</span></div>
            <div className="ci-cycle">
              {securityCycle.map((s, i) => {
                const Icon = s.icon;
                return (
                  <div key={s.step} className="ci-cycle-step">
                    <div className="ci-cycle-icon"><Icon size={18} /></div>
                    <div className="ci-cycle-num">{i + 1}</div>
                    <div className="ci-cycle-label">{s.step}</div>
                    <div className="ci-cycle-desc">{s.desc}</div>
                    {i < securityCycle.length - 1 && <div className="ci-cycle-arrow">→</div>}
                  </div>
                );
              })}
            </div>

            <h4 className="ci-subhead">Security Scan Targets</h4>
            <div className="ci-table-wrap">
              <div className="ci-table ci-table--head"><span>Target</span><span>Tool</span><span>Frequency</span></div>
              {[
                { target: 'Dependencies (npm)', tool: 'npm audit', freq: 'Weekly (CI)' },
                { target: 'Dependencies (composer)', tool: 'composer audit', freq: 'Weekly (CI)' },
                { target: 'RLS policies', tool: 'Custom test suite', freq: 'Every release' },
                { target: 'Authentication', tool: 'Manual + automated', freq: 'Monthly' },
                { target: 'API security', tool: 'OWASP checklist', freq: 'Monthly' },
                { target: 'Storage access', tool: 'Bucket policy review', freq: 'Monthly' },
                { target: 'Penetration test', tool: 'External firm', freq: 'Annually' },
              ].map((s) => (
                <div key={s.target} className="ci-table">
                  <span className="ci-bold">{s.target}</span>
                  <span>{s.tool}</span>
                  <span className="ci-muted">{s.freq}</span>
                </div>
              ))}
            </div>

            <h4 className="ci-subhead">PHI Safety Rules</h4>
            <div className="ci-two-col">
              <div className="ci-col">
                <h5 className="ci-col-head ci-col-head--danger">NEVER LOG</h5>
                {['Patient names', 'Clinical narrative', 'Passwords/tokens', 'Payment secrets', 'PHI content', 'Staff credentials'].map((item) => (
                  <div key={item} className="ci-checklist-item ci-checklist-item--danger"><AlertTriangle size={14} /><span>{item}</span></div>
                ))}
              </div>
              <div className="ci-col">
                <h5 className="ci-col-head ci-col-head--safe">SAFE TO LOG</h5>
                {['Patient ID (hashed)', 'Record type + action', 'Auth method + result', 'Payment status + amount', 'Event type + timestamp', 'Role + action'].map((item) => (
                  <div key={item} className="ci-checklist-item"><CheckCircle2 size={14} className="ci-icon-ok" /><span>{item}</span></div>
                ))}
              </div>
            </div>
          </div>
        )}

        {tab === 'roadmap' && (
          <div className="ci-section">
            <div className="ci-section__head"><TrendingUp size={16} /><span>Product Roadmap</span></div>
            <div className="ci-table-wrap">
              <div className="ci-table ci-table--head"><span>#</span><span>Item</span><span>Category</span><span>Status</span></div>
              {roadmap.map((r) => (
                <div key={r.item} className="ci-table">
                  <span className="ci-bold">{r.priority}</span>
                  <span>{r.item}</span>
                  <span className="ci-badge ci-badge--category">{r.category}</span>
                  <span className="ci-muted">{r.status}</span>
                </div>
              ))}
            </div>

            <h4 className="ci-subhead">Roadmap Categories</h4>
            <div className="ci-roadmap-legend">
              <div className="ci-legend-item"><span className="ci-legend-dot ci-legend-dot--now" /> NOW — Approved, starting next sprint</div>
              <div className="ci-legend-item"><span className="ci-legend-dot ci-legend-dot--next" /> NEXT — Approved, in planning</div>
              <div className="ci-legend-item"><span className="ci-legend-dot ci-legend-dot--later" /> LATER — Identified, not yet approved</div>
              <div className="ci-legend-item"><span className="ci-legend-dot ci-legend-dot--declined" /> DECLINED — Evaluated and rejected</div>
            </div>

            <h4 className="ci-subhead">New Feature Phase Template</h4>
            <div className="ci-flow ci-flow--wrap">
              {['BASELINE', 'CONTRACT', 'ARCH', 'SECURITY', 'RLS', 'IMPL', 'TESTS', 'E2E', 'PERF', 'DOCS', 'CHECKPOINT', 'REPORT'].map((step, i) => (
                <span key={step} className="ci-flow-step ci-flow-step--small">
                  <span className="ci-flow-num">{i + 1}</span>
                  <span className="ci-flow-label">{step}</span>
                </span>
              ))}
            </div>
          </div>
        )}

        {tab === 'report' && (
          <div className="ci-section">
            <div className="ci-section__head"><Activity size={16} /><span>Governance Report</span></div>
            <div className="ci-verdict-card ci-verdict-card--conditional">
              <div className="ci-verdict-card__header"><Flag size={24} /><h3>CONDITIONALLY READY</h3></div>
              <p>SWASTHYA is a technically complete, governed healthcare platform with 28 enterprise modules, continuous security/DR/performance governance, and a controlled product evolution framework. The platform is NOT "complete" — it is a continuously evolving healthcare system governed by evidence, safety, and security.</p>
            </div>

            <h4 className="ci-subhead">Platform Status</h4>
            <div className="ci-status-grid">
              {[
                { label: 'Active hospitals', value: String(governanceReport.hospitals) },
                { label: 'Active facilities', value: String(governanceReport.facilities) },
                { label: 'Available modules', value: String(governanceReport.modules) },
                { label: 'Implemented', value: String(governanceReport.implemented) },
                { label: 'Partial', value: String(governanceReport.partial) },
                { label: 'Uptime', value: governanceReport.uptime },
                { label: 'Open incidents', value: String(governanceReport.incidents) },
                { label: 'Critical defects', value: String(governanceReport.criticalDefects) },
                { label: 'High defects', value: String(governanceReport.highDefects) },
                { label: 'Security', value: governanceReport.securityFindings },
                { label: 'DR restore', value: governanceReport.drRestoreTime },
                { label: 'Load tested', value: governanceReport.loadTested },
              ].map((s) => (
                <div key={s.label} className="ci-status-item">
                  <span className="ci-status-label">{s.label}</span>
                  <span className="ci-status-value">{s.value}</span>
                </div>
              ))}
            </div>

            <h4 className="ci-subhead">Risk Register</h4>
            <div className="ci-table-wrap">
              <div className="ci-table ci-table--head"><span>Risk</span><span>Category</span><span>Mitigation</span></div>
              {[
                { risk: 'No external pen test', cat: 'Security', mitigation: 'Required before production' },
                { risk: 'No production PITR', cat: 'Infrastructure', mitigation: 'Required for go-live' },
                { risk: 'No real hospital UAT', cat: 'Clinical', mitigation: 'Required for acceptance' },
                { risk: 'External integrations not certified', cat: 'Interop', mitigation: 'Partner sandbox testing' },
                { risk: 'No production monitoring data', cat: 'Operations', mitigation: 'Deploy and observe' },
                { risk: 'PACS viewer partial', cat: 'Technical', mitigation: 'External vendor partnership' },
                { risk: 'HL7 adapter only', cat: 'Technical', mitigation: 'Complete when partner available' },
                { risk: 'No real-world incident data', cat: 'Operations', mitigation: 'Accumulates with deployment' },
              ].map((r) => (
                <div key={r.risk} className="ci-table">
                  <span className="ci-bold">{r.risk}</span>
                  <span className="ci-badge ci-badge--category">{r.cat}</span>
                  <span className="ci-muted">{r.mitigation}</span>
                </div>
              ))}
            </div>

            <h4 className="ci-subhead">Next Approved Priorities</h4>
            <div className="ci-checklist">
              {[
                'External penetration test by qualified firm',
                'First hospital deployment with authorization',
                'Production PITR/WAL archiving configuration',
                'External integration certification (FHIR, HL7, DICOM, payment)',
                'Multi-hospital expansion after first stable deployment',
              ].map((item) => (
                <div key={item} className="ci-checklist-item"><CheckCircle2 size={14} className="ci-icon-ok" /><span>{item}</span></div>
              ))}
            </div>

            <div className="ci-final-note">
              <AlertTriangle size={16} />
              <span><strong>DO NOT automatically create Phase 135.</strong> Wait for an approved product/clinical/operational requirement before generating the next engineering phase.</span>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
