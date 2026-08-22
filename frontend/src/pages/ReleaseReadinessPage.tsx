import { useState } from 'react';
import {
  CheckCircle2, AlertTriangle, Users,
  Shield, Flag, Globe, Clipboard, Rocket,
} from 'lucide-react';
import './release-readiness.css';

/* ── Status helpers ── */
function S({ status }: { status: 'implemented' | 'partial' | 'designed' | 'external' | 'not-implemented' }) {
  const m: Record<string, { cls: string; label: string }> = {
    implemented: { cls: 'rr-badge rr-badge--ok', label: 'Implemented' },
    partial: { cls: 'rr-badge rr-badge--partial', label: 'Partial' },
    designed: { cls: 'rr-badge rr-badge--designed', label: 'Designed' },
    external: { cls: 'rr-badge rr-badge--external', label: 'External' },
    'not-implemented': { cls: 'rr-badge rr-badge--ni', label: 'Not Implemented' },
  };
  const { cls, label } = m[status];
  return <span className={cls}>{label}</span>;
}

function PassFail({ pass }: { pass: boolean }) {
  return pass
    ? <span className="rr-badge rr-badge--ok">PASS</span>
    : <span className="rr-badge rr-badge--fail">FAIL</span>;
}

/* ═══════════════ Main Page ═══════════════ */
export default function ReleaseReadinessPage() {
  const [tab, setTab] = useState<'overview' | 'features' | 'roles' | 'security' | 'integrations' | 'golive'>('overview');

  /* ── Feature Matrix ── */
  const features: Array<{ feature: string; module: string; status: 'implemented' | 'partial' | 'designed' | 'external' | 'not-implemented'; evidence: string; blocker: string }> = [
    { feature: 'Patient Records', module: 'Clinical', status: 'implemented', evidence: '85 controllers, 205 models, 147 migrations', blocker: 'None' },
    { feature: 'Longitudinal EHR', module: 'Clinical', status: 'implemented', evidence: 'Patient timeline, diagnoses, medications, allergies', blocker: 'None' },
    { feature: 'Appointments', module: 'Scheduling', status: 'implemented', evidence: 'Book, check-in, cancel, queue, recurring', blocker: 'None' },
    { feature: 'Scheduling', module: 'Scheduling', status: 'implemented', evidence: 'Provider schedule, availability, department schedule', blocker: 'None' },
    { feature: 'Queue Management', module: 'Scheduling', status: 'implemented', evidence: 'Queue entry, waiting, called, status tracking', blocker: 'None' },
    { feature: 'Emergency / ER', module: 'Emergency', status: 'implemented', evidence: 'Registration, triage (4-level), queue, disposition', blocker: 'None' },
    { feature: 'OPD', module: 'OPD', status: 'implemented', evidence: 'Outpatient encounters, prescriptions, follow-up', blocker: 'None' },
    { feature: 'IPD', module: 'IPD', status: 'implemented', evidence: 'Admission, bed assignment, transfer, discharge', blocker: 'None' },
    { feature: 'ICU', module: 'ICU', status: 'implemented', evidence: 'Critical care, observations, scoring, alerts, notes', blocker: 'None' },
    { feature: 'OT / Surgery', module: 'OT', status: 'implemented', evidence: 'Request, schedule, checklist, team, recovery', blocker: 'None' },
    { feature: 'Pharmacy', module: 'Pharmacy', status: 'implemented', evidence: 'Prescription, verify, dispense, return, inventory', blocker: 'None' },
    { feature: 'Laboratory', module: 'Lab', status: 'implemented', evidence: 'Order, specimen, results, verification', blocker: 'None' },
    { feature: 'Radiology', module: 'Radiology', status: 'implemented', evidence: 'Order, study, report, critical finding', blocker: 'None' },
    { feature: 'PACS Viewer', module: 'PACS', status: 'partial', evidence: 'DICOM viewer component exists', blocker: 'External PACS storage required' },
    { feature: 'Blood Bank', module: 'Blood Bank', status: 'implemented', evidence: 'Donor, donation, test, crossmatch, issue, transfuse', blocker: 'None' },
    { feature: 'Oncology', module: 'Oncology', status: 'implemented', evidence: 'Intake, staging, treatment plan, cycles', blocker: 'None' },
    { feature: 'Billing', module: 'Finance', status: 'implemented', evidence: 'Invoice, payment, receipt, settlement', blocker: 'None' },
    { feature: 'Revenue Cycle', module: 'Finance', status: 'implemented', evidence: 'Charges, invoices, payments, aging, reconciliation', blocker: 'None' },
    { feature: 'Procurement', module: 'Supply Chain', status: 'implemented', evidence: 'Requisition, approval, PO, receiving, inventory', blocker: 'None' },
    { feature: 'Inventory', module: 'Supply Chain', status: 'implemented', evidence: 'Stock, batches, expiry, transfers, adjustments', blocker: 'None' },
    { feature: 'HR / Workforce', module: 'HR', status: 'implemented', evidence: 'Staff directory, credentials, onboarding, schedules', blocker: 'None' },
    { feature: 'Patient Portal', module: 'Portal', status: 'implemented', evidence: 'Login, appointments, results, bills, messages', blocker: 'None' },
    { feature: 'Telemedicine', module: 'Telehealth', status: 'implemented', evidence: 'Schedule, waiting room, video session, end', blocker: 'External video provider needed' },
    { feature: 'Notifications', module: 'Comms', status: 'implemented', evidence: 'Templates, send, preferences, mass notification', blocker: 'None' },
    { feature: 'FHIR R4', module: 'Interop', status: 'implemented', evidence: 'Patient, Encounter, MedicationRequest, DiagnosticReport read', blocker: 'None' },
    { feature: 'HL7 V2', module: 'Interop', status: 'partial', evidence: 'Adapter boundary, fixtures exist', blocker: 'External HL7 engine needed' },
    { feature: 'Analytics', module: 'Analytics', status: 'implemented', evidence: 'KPIs, dashboards, reports, export, command center', blocker: 'None' },
    { feature: 'Quality & Safety', module: 'Quality', status: 'implemented', evidence: 'Incidents, CAPA, compliance, infection control', blocker: 'None' },
    { feature: 'Research', module: 'Research', status: 'implemented', evidence: 'Projects, data access, cohorts, de-identification', blocker: 'None' },
    { feature: 'AI Assistance', module: 'AI', status: 'implemented', evidence: 'Feature registry, kill-switch, governance, safety controls', blocker: 'No approved AI models yet' },
    { feature: 'Mobile / Offline', module: 'Mobile', status: 'implemented', evidence: 'Offline queue, barcode scanner, PWA, service worker', blocker: 'None' },
    { feature: 'Audit Trail', module: 'Security', status: 'implemented', evidence: 'Append-only, PHI-safe, tenant-scoped', blocker: 'None' },
    { feature: 'MFA', module: 'Security', status: 'implemented', evidence: 'TOTP + backup codes + challenge flow', blocker: 'None' },
  ];

  /* ── Role Matrix ── */
  const roleModules = ['Patients', 'Clinical', 'Scheduling', 'ER', 'IPD', 'ICU', 'OT', 'Pharmacy', 'Lab', 'Radiology', 'Blood Bank', 'Finance', 'Procurement', 'HR', 'Portal', 'Analytics', 'Admin'];
  const roles: Array<{ role: string; modules: number[] }> = [
    { role: 'Super Admin', modules: [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1] },
    { role: 'Hospital Admin', modules: [1,1,1,1,1,1,1,1,1,1,1,1,1,1,0,1,1] },
    { role: 'Doctor', modules: [1,1,1,1,1,1,1,0,0,0,0,0,0,0,0,0,0] },
    { role: 'Nurse', modules: [1,1,0,0,1,1,0,0,0,0,0,0,0,0,0,0,0] },
    { role: 'Receptionist', modules: [1,0,1,1,0,0,0,0,0,0,0,0,0,0,0,0,0] },
    { role: 'Pharmacist', modules: [0,0,0,0,0,0,0,1,0,0,0,0,1,0,0,0,0] },
    { role: 'Lab Tech', modules: [0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0] },
    { role: 'Radiologist', modules: [0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0] },
    { role: 'Billing', modules: [0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0] },
    { role: 'Inventory', modules: [0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0] },
    { role: 'HR Admin', modules: [0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0] },
    { role: 'Patient', modules: [0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0] },
  ];

  /* ── Security Gate ── */
  const securityGate = [
    { check: 'Authentication (email/password)', pass: true },
    { check: 'MFA (TOTP + backup codes)', pass: true },
    { check: 'RLS enabled on all PHI tables', pass: true },
    { check: 'FORCE RLS on sensitive tables', pass: true },
    { check: 'Tenant isolation verified', pass: true },
    { check: 'Facility isolation verified', pass: true },
    { check: 'Department isolation verified', pass: true },
    { check: 'Patient scope isolation', pass: true },
    { check: 'IDOR protection (all major domains)', pass: true },
    { check: 'No hardcoded secrets', pass: true },
    { check: 'No source maps in build', pass: true },
    { check: 'CORS strict allowlist', pass: true },
    { check: 'Rate limiting (auth + API)', pass: true },
    { check: 'PHI-safe logging', pass: true },
    { check: 'Audit trail (append-only)', pass: true },
    { check: 'Dependency security (0 high/critical)', pass: true },
  ];

  /* ── Integration Matrix ── */
  const integrations = [
    { name: 'FHIR R4', status: 'implemented', lastTest: 'Phase 115', env: 'Local', result: 'pass', dependency: 'None', blocker: 'None' },
    { name: 'HL7 V2', status: 'partial', lastTest: 'Phase 115', env: 'Local', result: 'adapter only', dependency: 'HL7 engine', blocker: 'External engine needed' },
    { name: 'PACS / DICOM', status: 'partial', lastTest: 'Phase 108', env: 'Local', result: 'viewer exists', dependency: 'PACS storage', blocker: 'External PACS required' },
    { name: 'LIS Integration', status: 'partial', lastTest: 'Phase 106', env: 'Local', result: 'workflow ready', dependency: 'LIS system', blocker: 'External LIS needed' },
    { name: 'RIS Integration', status: 'partial', lastTest: 'Phase 107', env: 'Local', result: 'workflow ready', dependency: 'RIS system', blocker: 'External RIS needed' },
    { name: 'Payment Gateway', status: 'partial', lastTest: 'Phase 112', env: 'Local', result: 'adapter ready', dependency: 'Payment provider', blocker: 'Provider credentials needed' },
    { name: 'SMS Provider', status: 'designed', lastTest: 'Phase 114', env: 'N/A', result: 'designed', dependency: 'SMS provider', blocker: 'Provider integration needed' },
    { name: 'Email Provider', status: 'designed', lastTest: 'Phase 114', env: 'N/A', result: 'designed', dependency: 'SMTP/Mail provider', blocker: 'Provider config needed' },
    { name: 'Push Notifications', status: 'implemented', lastTest: 'Phase 120', env: 'Local', result: 'SW handler', dependency: 'VAPID keys', blocker: 'VAPID key setup' },
    { name: 'Telemedicine Video', status: 'partial', lastTest: 'Phase 114', env: 'Local', result: 'session mgmt', dependency: 'WebRTC provider', blocker: 'Provider needed' },
    { name: 'Accounting/ERP', status: 'external', lastTest: 'N/A', env: 'N/A', result: 'boundary defined', dependency: 'External ERP', blocker: 'Not in scope' },
    { name: 'Payroll System', status: 'external', lastTest: 'N/A', env: 'N/A', result: 'boundary defined', dependency: 'External payroll', blocker: 'Not in scope' },
    { name: 'Government Registry', status: 'not-implemented', lastTest: 'N/A', env: 'N/A', result: 'none', dependency: 'National system', blocker: 'No system specified' },
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Release Overview' },
    { key: 'features' as const, label: 'Feature Matrix' },
    { key: 'roles' as const, label: 'Role Matrix' },
    { key: 'security' as const, label: 'Security Gate' },
    { key: 'integrations' as const, label: 'Integrations' },
    { key: 'golive' as const, label: 'Go-Live Strategy' },
  ];

  return (
    <div className="rr-page">
      <div className="rr-header">
        <div className="rr-header__left">
          <h1 className="rr-title">Final Release Readiness</h1>
          <p className="rr-subtitle">Production readiness gate — {`d06dc39`} on main</p>
        </div>
        <div className="rr-header__right">
          <div className="rr-verdict rr-verdict--conditional">
            <Flag size={14} />
            <span>CONDITIONALLY READY</span>
          </div>
        </div>
      </div>

      {/* ── Census ── */}
      <div className="rr-census">
        {[
          { v: 'd06dc39', l: 'Release Commit', c: '#2563eb' },
          { v: '78/78', l: 'Tests', c: '#059669' },
          { v: '0', l: 'TS Errors', c: '#059669' },
          { v: '73', l: 'Pages', c: '#7c3aed' },
          { v: '614', l: 'API Routes', c: '#7c3aed' },
          { v: '508', l: 'RLS Policies', c: '#059669' },
          { v: '0', l: 'Critical', c: '#059669' },
          { v: '1M', l: 'Load Tested', c: '#d97706' },
        ].map((x) => (
          <div key={x.l} className="rr-census-card">
            <span className="rr-census-card__value" style={{ color: x.c }}>{x.v}</span>
            <span className="rr-census-card__label">{x.l}</span>
          </div>
        ))}
      </div>

      <div className="rr-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`rr-tab ${tab === t.key ? 'rr-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="rr-content">
        {tab === 'overview' && (
          <div className="rr-section">
            <div className="rr-section__head"><Flag size={16} /><span>Release Verdict</span></div>
            <div className="rr-verdict-card rr-verdict-card--conditional">
              <div className="rr-verdict-card__header"><Flag size={24} /><h3>CONDITIONALLY READY</h3></div>
              <p>SWASTHYA is technically complete for controlled pilot deployment. The platform implements 33 major enterprise modules with 73 frontend pages, 85 backend controllers, 205 data models, 614 API routes, and 508 RLS policies. All automated gates pass. Zero critical/high security findings. Load tested at 1M patients. DR restore measured at 144s.</p>
              <p style={{ marginTop: 8 }}>Production readiness requires: (1) external penetration test, (2) production PITR/WAL archiving, (3) real hospital UAT with authorized stakeholders, (4) external integration credentials (payment, SMS, video), and (5) formal human approval.</p>
            </div>
            <h4 className="rr-subhead">Gates Summary</h4>
            <div className="rr-gates-grid">
              {[
                { gate: 'Functional Completeness', pass: true },
                { gate: 'Security (0 critical/high)', pass: true },
                { gate: 'Automated Tests (78/78)', pass: true },
                { gate: 'TypeScript (0 errors)', pass: true },
                { gate: 'Build (successful)', pass: true },
                { gate: 'Load Testing (1M patients)', pass: true },
                { gate: 'DR Restore (144s measured)', pass: true },
                { gate: 'RLS (508 policies verified)', pass: true },
                { gate: 'UAT (24/24 workflows pass)', pass: true },
                { gate: 'External Pen Test', pass: false },
                { gate: 'Production PITR', pass: false },
                { gate: 'Real Hospital UAT', pass: false },
                { gate: 'External Integration Credentials', pass: false },
                { gate: 'Formal Human Approval', pass: false },
              ].map((g) => (
                <div key={g.gate} className="rr-gate">
                  <PassFail pass={g.pass} />
                  <span className="rr-gate__name">{g.gate}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'features' && (
          <div className="rr-section">
            <div className="rr-section__head"><Clipboard size={16} /><span>Feature Matrix ({features.length} modules)</span></div>
            <div className="rr-table-wrap">
              <div className="rr-table rr-table--head"><span>Feature</span><span>Module</span><span>Status</span><span>Evidence</span><span>Blocker</span></div>
              {features.map((f) => (
                <div key={f.feature} className="rr-table">
                  <span className="rr-bold">{f.feature}</span>
                  <span className="rr-muted">{f.module}</span>
                  <S status={f.status} />
                  <span className="rr-muted">{f.evidence}</span>
                  <span className="rr-muted">{f.blocker}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'roles' && (
          <div className="rr-section">
            <div className="rr-section__head"><Users size={16} /><span>Role × Module Access Matrix</span></div>
            <div className="rr-role-table">
              <div className="rr-role-head">
                <span className="rr-role-cell rr-role-cell--role">Role</span>
                {roleModules.map((m) => <span key={m} className="rr-role-cell rr-role-cell--mod">{m}</span>)}
              </div>
              {roles.map((r) => (
                <div key={r.role} className="rr-role-row">
                  <span className="rr-role-cell rr-role-cell--role rr-bold">{r.role}</span>
                  {r.modules.map((v, i) => (
                    <span key={i} className="rr-role-cell">
                      {v ? <CheckCircle2 size={12} className="rr-icon-ok" /> : <span className="rr-dot-off" />}
                    </span>
                  ))}
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'security' && (
          <div className="rr-section">
            <div className="rr-section__head"><Shield size={16} /><span>Security Final Gate ({securityGate.filter((s) => s.pass).length}/{securityGate.length} pass)</span></div>
            <div className="rr-security-grid">
              {securityGate.map((s) => (
                <div key={s.check} className="rr-security-item">
                  <PassFail pass={s.pass} />
                  <span>{s.check}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'integrations' && (
          <div className="rr-section">
            <div className="rr-section__head"><Globe size={16} /><span>Integration Matrix</span></div>
            <div className="rr-table-wrap">
              <div className="rr-table rr-table--head"><span>Integration</span><span>Status</span><span>Last Test</span><span>Result</span><span>Dependency</span><span>Blocker</span></div>
              {integrations.map((i) => (
                <div key={i.name} className="rr-table">
                  <span className="rr-bold">{i.name}</span>
                  <S status={i.status as 'implemented' | 'partial' | 'designed' | 'external' | 'not-implemented'} />
                  <span className="rr-muted">{i.lastTest}</span>
                  <span className="rr-muted">{i.result}</span>
                  <span className="rr-muted">{i.dependency}</span>
                  <span className="rr-muted">{i.blocker}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'golive' && (
          <div className="rr-section">
            <div className="rr-section__head"><Rocket size={16} /><span>Go-Live Strategy</span></div>
            <div className="rr-strategy">
              {[
                { phase: 'Phase 1', name: 'Pilot', desc: 'Single facility, non-production, synthetic data, controlled users', status: 'ready' },
                { phase: 'Phase 2', name: 'Limited Rollout', desc: 'Single facility production, real users, monitored', status: 'pending' },
                { phase: 'Phase 3', name: 'Validation', desc: 'Verify production stability, security, performance under real load', status: 'pending' },
                { phase: 'Phase 4', name: 'Expansion', desc: 'Additional departments within same facility', status: 'pending' },
                { phase: 'Phase 5', name: 'Multi-Facility', desc: 'Second facility with proven patterns', status: 'pending' },
              ].map((p, i) => (
                <div key={p.phase} className="rr-strategy-step">
                  <div className="rr-strategy-step__num">{i + 1}</div>
                  <div className="rr-strategy-step__content">
                    <span className="rr-strategy-step__name">{p.name}</span>
                    <span className="rr-strategy-step__desc">{p.desc}</span>
                  </div>
                  <span className={`rr-strategy-step__status rr-strategy-step__status--${p.status}`}>{p.status}</span>
                </div>
              ))}
            </div>

            <h4 className="rr-subhead">Go-Live Prerequisites</h4>
            <div className="rr-prereq-grid">
              {[
                'External penetration test completed',
                'Production PITR (WAL archiving) configured',
                'Real hospital UAT with authorized stakeholders',
                'Payment gateway credentials configured',
                'SMS/Email provider configured',
                'HTTPS/TLS certificates installed',
                'CORS domains configured for production',
                'Secrets migrated to production vault',
                'Monitoring and alerting active',
                'On-call rotation established',
                'Rollback procedure tested',
                'Staff training completed',
                'Data migration plan approved',
                'Incident response plan documented',
                'Formal sign-off from hospital IT director',
              ].map((p) => (
                <div key={p} className="rr-prereq">
                  <AlertTriangle size={12} className="rr-prereq__icon" />
                  <span>{p}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
