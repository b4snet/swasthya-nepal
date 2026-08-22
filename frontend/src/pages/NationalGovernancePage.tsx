import { useState } from 'react';
import {
  CheckCircle2, Shield, Lock, TrendingUp, AlertTriangle, Flag, Heart,
  Clipboard,
} from 'lucide-react';
import './national-governance.css';

/* ═══════════════ Main Page ═══════════════ */
export default function NationalGovernancePage() {
  const [tab, setTab] = useState<'overview' | 'features' | 'governance' | 'risks' | 'roadmap'>('overview');

  const features = [
    { feature: 'Patient Records', status: 'implemented' },
    { feature: 'Appointments', status: 'implemented' },
    { feature: 'Emergency', status: 'implemented' },
    { feature: 'IPD', status: 'implemented' },
    { feature: 'ICU', status: 'implemented' },
    { feature: 'OT / Surgery', status: 'implemented' },
    { feature: 'Pharmacy', status: 'implemented' },
    { feature: 'Laboratory', status: 'implemented' },
    { feature: 'Radiology', status: 'implemented' },
    { feature: 'Blood Bank', status: 'implemented' },
    { feature: 'Oncology', status: 'implemented' },
    { feature: 'Billing', status: 'implemented' },
    { feature: 'Procurement', status: 'implemented' },
    { feature: 'Inventory', status: 'implemented' },
    { feature: 'HR / Workforce', status: 'implemented' },
    { feature: 'Patient Portal', status: 'implemented' },
    { feature: 'Telemedicine', status: 'implemented' },
    { feature: 'Notifications', status: 'implemented' },
    { feature: 'FHIR R4', status: 'implemented' },
    { feature: 'HL7 V2', status: 'partial' },
    { feature: 'PACS/DICOM', status: 'partial' },
    { feature: 'Analytics', status: 'implemented' },
    { feature: 'Quality & Safety', status: 'implemented' },
    { feature: 'Research', status: 'implemented' },
    { feature: 'AI Assistance', status: 'implemented' },
    { feature: 'Mobile / Offline', status: 'implemented' },
    { feature: 'Security (RLS, MFA)', status: 'implemented' },
    { feature: 'Audit Trail', status: 'implemented' },
  ];

  const governance = [
    { area: 'Tenant Isolation', status: true, schedule: 'Every release' },
    { area: 'Facility Isolation', status: true, schedule: 'Every release' },
    { area: 'RLS Regression', status: true, schedule: 'Every release' },
    { area: 'Dependency Scan', status: true, schedule: 'Weekly (CI)' },
    { area: 'Security Audit', status: true, schedule: 'Monthly' },
    { area: 'Penetration Test', status: false, schedule: 'Annually (required)' },
    { area: 'DR Exercise', status: true, schedule: 'Quarterly' },
    { area: 'Backup Restore Test', status: true, schedule: 'Quarterly' },
    { area: 'Release Approval', status: true, schedule: 'Every release' },
    { area: 'Clinical Review', status: true, schedule: 'Clinical changes' },
    { area: 'Data Governance', status: true, schedule: 'Continuous' },
    { area: 'Incident Response', status: true, schedule: 'As needed' },
  ];

  const risks = [
    { risk: 'No external pen test', severity: 'Medium', mitigation: 'Required before production' },
    { risk: 'No production PITR', severity: 'Medium', mitigation: 'Required for go-live' },
    { risk: 'No real hospital UAT', severity: 'Medium', mitigation: 'Required for acceptance' },
    { risk: 'External integrations not certified', severity: 'Medium', mitigation: 'Partner sandbox testing' },
    { risk: 'No real-world incident data', severity: 'Low', mitigation: 'Accumulates with deployment' },
  ];

  const roadmap = [
    { item: 'External penetration test', priority: 1, status: 'Required' },
    { item: 'Production PITR configuration', priority: 2, status: 'Required' },
    { item: 'First hospital deployment', priority: 3, status: 'Requires authorization' },
    { item: 'External integration certification', priority: 4, status: 'Requires partners' },
    { item: 'Multi-hospital expansion', priority: 5, status: 'After first stable' },
    { item: 'Advanced analytics', priority: 6, status: 'After operational data' },
    { item: 'AI model integration', priority: 7, status: 'After approved models' },
  ];

  const tabs = [
    { key: 'overview' as const, label: 'National Operating Model' },
    { key: 'features' as const, label: 'Feature Matrix' },
    { key: 'governance' as const, label: 'Governance' },
    { key: 'risks' as const, label: 'Risks & Limitations' },
    { key: 'roadmap' as const, label: 'Roadmap' },
  ];

  const implemented = features.filter((f) => f.status === 'implemented').length;

  return (
    <div className="ng-page">
      <div className="ng-header">
        <div className="ng-header__left">
          <h1 className="ng-title">National Operating Model</h1>
          <p className="ng-subtitle">Governance framework and continuous improvement for SWASTHYA</p>
        </div>
        <div className="ng-header__right">
          <div className="ng-verdict-pill ng-verdict-pill--conditional">
            <Flag size={14} />
            <span>CONDITIONALLY READY</span>
          </div>
        </div>
      </div>

      <div className="ng-census">
        <div className="ng-census-card">
          <div className="ng-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><CheckCircle2 size={18} /></div>
          <div className="ng-census-card__info">
            <span className="ng-census-card__value">{implemented}/{features.length}</span>
            <span className="ng-census-card__label">Features</span>
          </div>
        </div>
        <div className="ng-census-card">
          <div className="ng-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Shield size={18} /></div>
          <div className="ng-census-card__info">
            <span className="ng-census-card__value">0</span>
            <span className="ng-census-card__label">Critical Risks</span>
          </div>
        </div>
        <div className="ng-census-card">
          <div className="ng-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Lock size={18} /></div>
          <div className="ng-census-card__info">
            <span className="ng-census-card__value">508</span>
            <span className="ng-census-card__label">RLS Policies</span>
          </div>
        </div>
        <div className="ng-census-card">
          <div className="ng-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Heart size={18} /></div>
          <div className="ng-census-card__info">
            <span className="ng-census-card__value">0</span>
            <span className="ng-census-card__label">Open Incidents</span>
          </div>
        </div>
      </div>

      <div className="ng-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`ng-tab ${tab === t.key ? 'ng-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="ng-content">
        {tab === 'overview' && (
          <div className="ng-section">
            <div className="ng-section__head"><Flag size={16} /><span>National Operating Status</span></div>
            <div className="ng-verdict-card ng-verdict-card--conditional">
              <div className="ng-verdict-card__header"><Flag size={24} /><h3>CONDITIONALLY READY</h3></div>
              <p>SWASTHYA is technically complete for hospital deployment. The platform implements {implemented} major features across clinical, operational, financial, and administrative domains. The governance framework is established. However, actual national deployment requires human authorization, external penetration testing, production PITR, and real hospital UAT.</p>
            </div>
            <h4 className="ng-subhead">Honest Status</h4>
            <div className="ng-status-grid">
              {[
                { label: 'Hospitals in production', value: '0', status: 'pending' },
                { label: 'Real PHI processed', value: 'None', status: 'pending' },
                { label: 'External pen test', value: 'Not performed', status: 'pending' },
                { label: 'Production PITR', value: 'Not configured', status: 'pending' },
                { label: 'External integrations certified', value: '1/34', status: 'pending' },
              ].map((s) => (
                <div key={s.label} className="ng-status-item">
                  <span className="ng-status-label">{s.label}</span>
                  <span className="ng-status-value">{s.value}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'features' && (
          <div className="ng-section">
            <div className="ng-section__head"><Clipboard size={16} /><span>Feature Matrix ({implemented}/{features.length} implemented)</span></div>
            <div className="ng-feature-grid">
              {features.map((f) => (
                <div key={f.feature} className="ng-feature-item">
                  {f.status === 'implemented'
                    ? <CheckCircle2 size={14} className="ng-icon-ok" />
                    : <AlertTriangle size={14} className="ng-icon-partial" />}
                  <span className="ng-feature-name">{f.feature}</span>
                  <span className={`ng-badge ng-badge--${f.status}`}>{f.status}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'governance' && (
          <div className="ng-section">
            <div className="ng-section__head"><Shield size={16} /><span>Governance Framework</span></div>
            <div className="ng-table-wrap">
              <div className="ng-table ng-table--head"><span>Area</span><span>Status</span><span>Schedule</span></div>
              {governance.map((g) => (
                <div key={g.area} className="ng-table">
                  <span className="ng-bold">{g.area}</span>
                  <span>{g.status ? <CheckCircle2 size={14} className="ng-icon-ok" /> : <AlertTriangle size={14} className="ng-icon-partial" />}</span>
                  <span className="ng-muted">{g.schedule}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'risks' && (
          <div className="ng-section">
            <div className="ng-section__head"><AlertTriangle size={16} /><span>Accepted Risks & Limitations</span></div>
            <div className="ng-table-wrap">
              <div className="ng-table ng-table--head"><span>Risk</span><span>Severity</span><span>Mitigation</span></div>
              {risks.map((r) => (
                <div key={r.risk} className="ng-table">
                  <span className="ng-bold">{r.risk}</span>
                  <span className={`ng-sev ng-sev--${r.severity.toLowerCase()}`}>{r.severity}</span>
                  <span className="ng-muted">{r.mitigation}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'roadmap' && (
          <div className="ng-section">
            <div className="ng-section__head"><TrendingUp size={16} /><span>Current Roadmap</span></div>
            <div className="ng-table-wrap">
              <div className="ng-table ng-table--head"><span>Priority</span><span>Item</span><span>Status</span></div>
              {roadmap.map((r) => (
                <div key={r.item} className="ng-table">
                  <span className="ng-bold">{r.priority}</span>
                  <span>{r.item}</span>
                  <span className="ng-muted">{r.status}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
