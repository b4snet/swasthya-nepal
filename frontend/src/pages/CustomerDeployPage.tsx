import { useState } from 'react';
import {
  CheckCircle2, Building2, GraduationCap, Headphones,
  Clipboard, Rocket, Settings,
} from 'lucide-react';
import './customer-deploy.css';

function Chk({ checked }: { checked: boolean }) {
  return checked
    ? <CheckCircle2 size={14} className="cd-icon-ok" />
    : <span className="cd-chk-empty" />;
}

/* ═══════════════ Main Page ═══════════════ */
export default function CustomerDeployPage() {
  const [tab, setTab] = useState<'overview' | 'onboarding' | 'templates' | 'training' | 'support'>('overview');

  const onboardingSteps = [
    { step: 'Organization setup', status: false },
    { step: 'Facility setup', status: false },
    { step: 'Module entitlement', status: false },
    { step: 'Department configuration', status: false },
    { step: 'Role configuration', status: false },
    { step: 'Staff setup', status: false },
    { step: 'Branding/configuration', status: false },
    { step: 'Integration setup', status: false },
    { step: 'Data migration (if applicable)', status: false },
    { step: 'Training', status: false },
    { step: 'Go-live support', status: false },
    { step: 'Hypercare', status: false },
  ];

  const templates = [
    { name: 'General Hospital', desc: 'Full module set, all departments', target: 'Large multi-department hospitals' },
    { name: 'Clinic', desc: 'Outpatient-focused, minimal IPD', target: 'Small clinics, polyclinics' },
    { name: 'Multi-Facility', desc: 'Organization-level, multiple sites', target: 'Hospital chains, networks' },
    { name: 'Specialty Hospital', desc: 'Focused modules (cardiac, cancer)', target: 'Specialty care centers' },
  ];

  const modules = [
    { pkg: 'Essential', modules: 'Patient, Appointments, Billing, Pharmacy', target: 'Small clinics' },
    { pkg: 'Standard', modules: 'Essential + Lab, Radiology, IPD', target: 'Mid-size hospitals' },
    { pkg: 'Professional', modules: 'Standard + ICU, OT, Blood Bank, Procurement', target: 'Large hospitals' },
    { pkg: 'Enterprise', modules: 'Professional + Analytics, Research, AI, Multi-facility', target: 'Hospital networks' },
  ];

  const training = [
    { role: 'Hospital Admin', duration: '4 hours', topics: 'Organization setup, module config, staff management' },
    { role: 'Doctor', duration: '2 hours', topics: 'Clinical workspace, encounters, prescriptions, orders' },
    { role: 'Nurse', duration: '2 hours', topics: 'Ward tasks, vitals, care plans, handovers' },
    { role: 'Pharmacist', duration: '2 hours', topics: 'Prescriptions, dispensing, inventory' },
    { role: 'Lab Technician', duration: '2 hours', topics: 'Lab worklist, specimen, results' },
    { role: 'Radiologist', duration: '2 hours', topics: 'Imaging worklist, reports, critical findings' },
    { role: 'Billing Staff', duration: '2 hours', topics: 'Charges, invoices, payments, reconciliation' },
    { role: 'HR Admin', duration: '2 hours', topics: 'Staff directory, credentials, onboarding' },
    { role: 'Patient', duration: '30 min', topics: 'Portal login, appointments, results, bills' },
  ];

  const support = [
    { channel: 'Email', availability: '24/7', response: '< 4 hours' },
    { channel: 'Phone', availability: 'Business hours', response: '< 1 hour' },
    { channel: 'Slack/Teams', availability: 'Business hours', response: '< 30 min' },
    { channel: 'Emergency', availability: '24/7', response: '< 15 min' },
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Deployment Overview' },
    { key: 'onboarding' as const, label: 'Onboarding Checklist' },
    { key: 'templates' as const, label: 'Configuration Templates' },
    { key: 'training' as const, label: 'Training Materials' },
    { key: 'support' as const, label: 'Customer Support' },
  ];

  return (
    <div className="cd-page">
      <div className="cd-header">
        <div className="cd-header__left">
          <h1 className="cd-title">Customer Deployment Readiness</h1>
          <p className="cd-subtitle">Repeatable hospital onboarding, training, and customer success</p>
        </div>
        <div className="cd-header__right">
          <div className="cd-verdict-pill cd-verdict-pill--ready">
            <Rocket size={14} />
            <span>DEPLOYMENT READY</span>
          </div>
        </div>
      </div>

      <div className="cd-census">
        <div className="cd-census-card">
          <div className="cd-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Building2 size={18} /></div>
          <div className="cd-census-card__info">
            <span className="cd-census-card__value">4</span>
            <span className="cd-census-card__label">Config Templates</span>
          </div>
        </div>
        <div className="cd-census-card">
          <div className="cd-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Settings size={18} /></div>
          <div className="cd-census-card__info">
            <span className="cd-census-card__value">4</span>
            <span className="cd-census-card__label">Module Packages</span>
          </div>
        </div>
        <div className="cd-census-card">
          <div className="cd-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><GraduationCap size={18} /></div>
          <div className="cd-census-card__info">
            <span className="cd-census-card__value">9</span>
            <span className="cd-census-card__label">Training Roles</span>
          </div>
        </div>
        <div className="cd-census-card">
          <div className="cd-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Headphones size={18} /></div>
          <div className="cd-census-card__info">
            <span className="cd-census-card__value">4</span>
            <span className="cd-census-card__label">Support Channels</span>
          </div>
        </div>
      </div>

      <div className="cd-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`cd-tab ${tab === t.key ? 'cd-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="cd-content">
        {tab === 'overview' && (
          <div className="cd-section">
            <div className="cd-section__head"><Rocket size={16} /><span>Deployment Readiness</span></div>
            <div className="cd-verdict-card cd-verdict-card--ready">
              <div className="cd-verdict-card__header"><CheckCircle2 size={24} /><h3>DEPLOYMENT READY</h3></div>
              <p>SWASTHYA is repeatably deployable to additional hospitals. The platform supports 4 configuration templates, 4 module packages, 9 role-specific training programs, and 4 customer support channels. Onboarding can be completed in approximately 8 weeks.</p>
            </div>
            <h4 className="cd-subhead">Deployment Timeline</h4>
            <div className="cd-timeline">
              {[
                { week: 'Week 1', activity: 'Kickoff, environment setup, org/facility config' },
                { week: 'Week 2-3', activity: 'Departments, roles, staff, branding, modules' },
                { week: 'Week 4-5', activity: 'Integrations, data migration, testing' },
                { week: 'Week 6-7', activity: 'Role-specific training for all user groups' },
                { week: 'Week 8', activity: 'Production deployment, monitoring, hypercare' },
              ].map((t) => (
                <div key={t.week} className="cd-timeline-item">
                  <span className="cd-timeline-week">{t.week}</span>
                  <span className="cd-timeline-activity">{t.activity}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'onboarding' && (
          <div className="cd-section">
            <div className="cd-section__head"><Clipboard size={16} /><span>Onboarding Checklist</span></div>
            <div className="cd-checklist">
              {onboardingSteps.map((s) => (
                <div key={s.step} className="cd-check-item">
                  <Chk checked={s.status} />
                  <span>{s.step}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'templates' && (
          <div className="cd-section">
            <div className="cd-section__head"><Building2 size={16} /><span>Configuration Templates</span></div>
            <div className="cd-template-grid">
              {templates.map((t) => (
                <div key={t.name} className="cd-template-card">
                  <span className="cd-template-name">{t.name}</span>
                  <span className="cd-template-desc">{t.desc}</span>
                  <span className="cd-template-target">{t.target}</span>
                </div>
              ))}
            </div>
            <h4 className="cd-subhead">Module Packages</h4>
            <div className="cd-table-wrap">
              <div className="cd-table cd-table--head"><span>Package</span><span>Modules</span><span>Target</span></div>
              {modules.map((m) => (
                <div key={m.pkg} className="cd-table">
                  <span className="cd-bold">{m.pkg}</span>
                  <span className="cd-muted">{m.modules}</span>
                  <span>{m.target}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'training' && (
          <div className="cd-section">
            <div className="cd-section__head"><GraduationCap size={16} /><span>Training Materials</span></div>
            <div className="cd-table-wrap">
              <div className="cd-table cd-table--head"><span>Role</span><span>Duration</span><span>Topics</span></div>
              {training.map((t) => (
                <div key={t.role} className="cd-table">
                  <span className="cd-bold">{t.role}</span>
                  <span className="cd-mono">{t.duration}</span>
                  <span className="cd-muted">{t.topics}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'support' && (
          <div className="cd-section">
            <div className="cd-section__head"><Headphones size={16} /><span>Customer Support</span></div>
            <div className="cd-table-wrap">
              <div className="cd-table cd-table--head"><span>Channel</span><span>Availability</span><span>Response Time</span></div>
              {support.map((s) => (
                <div key={s.channel} className="cd-table">
                  <span className="cd-bold">{s.channel}</span>
                  <span>{s.availability}</span>
                  <span className="cd-mono">{s.response}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
