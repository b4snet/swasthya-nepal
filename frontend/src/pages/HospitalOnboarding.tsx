/**
 * Hospital Onboarding Wizard
 *
 * A multi-step guided setup for configuring a new hospital organization.
 * Connects to the existing backend OnboardingService (5 steps).
 *
 * Steps:
 * 1. Organization — name, address, timezone, currency
 * 2. Facility — hospital name, code, address
 * 3. Departments — select which departments exist
 * 4. Modules — select operational modules
 * 5. Review & Activate
 */

import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { Button, Input, Select, Alert } from '../components/ui';
import {
  Building2,
  Hospital,
  Layers,
  Package,
  CheckCircle,
  ChevronRight,
  ChevronLeft,
  Users,
  Bed,
  Pill,
  FlaskConical,
  ScanLine,
  WalletCards,
  Stethoscope,
  Siren,
  Heart,
  Brain,
  Bone,
  Scissors,
  Baby,
  TestTube,
  Camera,
  Droplets,
  Dumbbell,
  Apple,
  Banknote,
  FileText,
  ShoppingCart,
  MonitorSmartphone,
} from 'lucide-react';
import './onboarding.css';

/* ── Steps definition ── */
const STEPS = [
  { key: 'organization', label: 'Organization', icon: Building2, description: 'Hospital identity and contact' },
  { key: 'facility', label: 'Facility', icon: Hospital, description: 'Hospital facility details' },
  { key: 'departments', label: 'Departments', icon: Layers, description: 'Medical departments and services' },
  { key: 'modules', label: 'Modules', icon: Package, description: 'Operational modules to enable' },
  { key: 'review', label: 'Review & Activate', icon: CheckCircle, description: 'Review and go live' },
];

/* ── Department templates (Lucide icons only — no emoji) ── */
const DEPARTMENT_TEMPLATES = [
  { code: 'EMERGENCY', name: 'Emergency', category: 'clinical', icon: Siren },
  { code: 'INTERNAL_MED', name: 'Internal Medicine', category: 'clinical', icon: Stethoscope },
  { code: 'CARDIOLOGY', name: 'Cardiology', category: 'clinical', icon: Heart },
  { code: 'NEUROLOGY', name: 'Neurology', category: 'clinical', icon: Brain },
  { code: 'ORTHOPEDICS', name: 'Orthopedics', category: 'surgical', icon: Bone },
  { code: 'GENERAL_SURG', name: 'General Surgery', category: 'surgical', icon: Scissors },
  { code: 'OBSTETRICS', name: 'Obstetrics & Gynecology', category: 'womens_health', icon: Baby },
  { code: 'PEDIATRICS', name: 'Pediatrics', category: 'womens_health', icon: Baby },
  { code: 'ICU', name: 'Intensive Care Unit', category: 'critical_care', icon: Building2 },
  { code: 'LABORATORY', name: 'Laboratory', category: 'diagnostics', icon: TestTube },
  { code: 'RADIOLOGY', name: 'Radiology', category: 'diagnostics', icon: Camera },
  { code: 'PHARMACY', name: 'Pharmacy', category: 'pharmacy', icon: Pill },
  { code: 'BLOOD_BANK', name: 'Blood Bank', category: 'diagnostics', icon: Droplets },
  { code: 'PHYSIOTHERAPY', name: 'Physiotherapy', category: 'allied_health', icon: Dumbbell },
  { code: 'NUTRITION', name: 'Nutrition & Dietetics', category: 'allied_health', icon: Apple },
  { code: 'FINANCE', name: 'Finance', category: 'administrative', icon: Banknote },
  { code: 'BILLING', name: 'Billing', category: 'administrative', icon: WalletCards },
  { code: 'PROCUREMENT', name: 'Procurement', category: 'administrative', icon: ShoppingCart },
  { code: 'HR', name: 'Human Resources', category: 'administrative', icon: Users },
  { code: 'IT', name: 'Information Technology', category: 'administrative', icon: MonitorSmartphone },
];

/* ── Module definitions ── */
const AVAILABLE_MODULES = [
  { code: 'patient_management', name: 'Patient Management', description: 'Registration, demographics, MRN', icon: Users, required: true },
  { code: 'opd', name: 'Outpatient Department', description: 'OPD consultations and follow-up', icon: Stethoscope },
  { code: 'ipd', name: 'Inpatient Department', description: 'Admissions, beds, ward management', icon: Bed },
  { code: 'emergency', name: 'Emergency', description: 'ER triage, treatment, disposition', icon: Siren },
  { code: 'icu', name: 'ICU / Critical Care', description: 'ICU monitoring and care', icon: Building2 },
  { code: 'ot', name: 'Operating Theatre', description: 'Surgical scheduling and documentation', icon: Scissors },
  { code: 'pharmacy', name: 'Pharmacy', description: 'Prescriptions, dispensing, inventory', icon: Pill },
  { code: 'laboratory', name: 'Laboratory', description: 'Lab orders, samples, results', icon: FlaskConical },
  { code: 'radiology', name: 'Radiology', description: 'Imaging studies and reporting', icon: ScanLine },
  { code: 'blood_bank', name: 'Blood Bank', description: 'Blood components and transfusion', icon: Droplets },
  { code: 'billing', name: 'Billing & Finance', description: 'Charges, invoices, payments', icon: WalletCards },
  { code: 'procurement', name: 'Procurement & Inventory', description: 'Supply chain and stock management', icon: Package },
  { code: 'referrals', name: 'Referrals', description: 'Internal and external referrals', icon: FileText },
  { code: 'patient_portal', name: 'Patient Portal', description: 'Patient self-service portal', icon: MonitorSmartphone },
  { code: 'notifications', name: 'Notifications', description: 'Communication and alerts', icon: FileText },
  { code: 'reports', name: 'Reporting & Analytics', description: 'Dashboards and reports', icon: FileText },
];

type OnboardingData = {
  organization: {
    name: string;
    legalName: string;
    address: string;
    city: string;
    country: string;
    phone: string;
    email: string;
    timezone: string;
    currency: string;
  };
  facility: {
    name: string;
    code: string;
    address: string;
    city: string;
    phone: string;
  };
  departments: string[];
  modules: string[];
};

export function HospitalOnboarding() {
  const navigate = useNavigate();
  const [step, setStep] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sessionId, setSessionId] = useState<string | null>(null);
  const [data, setData] = useState<OnboardingData>({
    organization: {
      name: '',
      legalName: '',
      address: '',
      city: '',
      country: 'Nepal',
      phone: '',
      email: '',
      timezone: 'Asia/Kathmandu',
      currency: 'NPR',
    },
    facility: {
      name: '',
      code: '',
      address: '',
      city: '',
      phone: '',
    },
    departments: [],
    modules: ['patient_management'],
  });

  const progress = ((step + 1) / STEPS.length) * 100;

  /* ── Create onboarding session ── */
  const createSession = async () => {
    try {
      const res = await api.request<{ session: any }>('/api/v1/onboarding', {
        method: 'POST',
        body: {
          organization: data.organization,
          facility: data.facility,
          modules: data.modules,
        },
      });
      setSessionId(res.session.id);
      return res.session.id;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create onboarding session');
      return null;
    }
  };

  /* ── Save current step ── */
  const saveStep = async (stepNum: number, stepData: any) => {
    if (!sessionId) {
      const id = await createSession();
      if (!id) return false;
    }
    try {
      await api.request(`/api/v1/onboarding/${sessionId}`, {
        method: 'PUT',
        body: { step: stepNum, data: stepData },
      });
      return true;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save');
      return false;
    }
  };

  /* ── Activate ── */
  const activate = async () => {
    setLoading(true);
    setError(null);
    try {
      if (!sessionId) {
        const id = await createSession();
        if (!id) { setLoading(false); return; }
      }
      await api.request(`/api/v1/onboarding/${sessionId}/activate`, { method: 'POST' });
      navigate('/admin', { replace: true });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Activation failed');
    } finally {
      setLoading(false);
    }
  };

  /* ── Navigation ── */
  const goNext = async () => {
    setError(null);
    const stepNum = step + 2; // backend is 1-indexed
    let stepData: any;
    switch (step) {
      case 0: stepData = data.organization; break;
      case 1: stepData = data.facility; break;
      case 2: stepData = { departments: data.departments }; break;
      case 3: stepData = { modules: data.modules }; break;
    }
    if (stepData) await saveStep(stepNum, stepData);
    if (step < STEPS.length - 1) setStep(step + 1);
  };

  const goBack = () => {
    if (step > 0) setStep(step - 1);
  };

  const toggleDept = (code: string) => {
    setData((d) => ({
      ...d,
      departments: d.departments.includes(code)
        ? d.departments.filter((c) => c !== code)
        : [...d.departments, code],
    }));
  };

  const toggleModule = (code: string) => {
    setData((d) => ({
      ...d,
      modules: d.modules.includes(code)
        ? d.modules.filter((c) => c !== code)
        : [...d.modules, code],
    }));
  };

  const updateOrg = (field: string, value: string) => {
    setData((d) => ({ ...d, organization: { ...d.organization, [field]: value } }));
  };

  const updateFac = (field: string, value: string) => {
    setData((d) => ({ ...d, facility: { ...d.facility, [field]: value } }));
  };

  const canProceed = () => {
    switch (step) {
      case 0: return data.organization.name.trim().length > 0;
      case 1: return data.facility.name.trim().length > 0;
      case 2: return data.departments.length > 0;
      case 3: return data.modules.length > 0;
      case 4: return true;
      default: return true;
    }
  };

  return (
    <div className="onboarding">
      <div className="onboarding__container">
        {/* Header */}
        <div className="onboarding__header">
          <div className="onboarding__logo">
            <svg width="32" height="32" viewBox="0 0 28 28" fill="none">
              <rect width="28" height="28" rx="6" fill="#0f766e"/>
              <path d="M8 14h12M14 8v12" stroke="#fff" strokeWidth="2.2" strokeLinecap="round"/>
            </svg>
            <span className="onboarding__brand">Swasthya</span>
          </div>
          <h1 className="onboarding__title">Hospital setup</h1>
          <p className="onboarding__subtitle">Configure your hospital in a few steps. You can save and continue later.</p>
        </div>

        {/* Progress bar */}
        <div className="onboarding__progress">
          <div className="onboarding__progress-bar">
            <div className="onboarding__progress-fill" style={{ width: `${progress}%` }} />
          </div>
          <span className="onboarding__progress-text">Step {step + 1} of {STEPS.length}</span>
        </div>

        {/* Step indicators */}
        <div className="onboarding__steps">
          {STEPS.map((s, i) => (
            <div
              key={s.key}
              className={`onboarding__step ${i === step ? 'onboarding__step--active' : ''} ${i < step ? 'onboarding__step--done' : ''}`}
            >
              <div className="onboarding__step-icon">
                {i < step ? <CheckCircle size={16} /> : <s.icon size={16} />}
              </div>
              <span className="onboarding__step-label">{s.label}</span>
            </div>
          ))}
        </div>

        {/* Error */}
        {error && <Alert tone="danger">{error}</Alert>}

        {/* Step content */}
        <div className="onboarding__content">
          {step === 0 && (
            <div className="onboarding__form">
              <h2 className="onboarding__form-title">Organization details</h2>
              <p className="onboarding__form-desc">Basic information about your hospital organization.</p>
              <div className="onboarding__fields">
                <Input label="Organization name" value={data.organization.name} onChange={(e) => updateOrg('name', e.target.value)} required placeholder="e.g. Nepal Medical Center" />
                <Input label="Legal name" value={data.organization.legalName} onChange={(e) => updateOrg('legalName', e.target.value)} placeholder="Legal entity name" />
                <Input label="Address" value={data.organization.address} onChange={(e) => updateOrg('address', e.target.value)} placeholder="Street address" />
                <Input label="City" value={data.organization.city} onChange={(e) => updateOrg('city', e.target.value)} placeholder="e.g. Kathmandu" />
                <Input label="Phone" value={data.organization.phone} onChange={(e) => updateOrg('phone', e.target.value)} placeholder="+977-…" />
                <Input label="Email" type="email" value={data.organization.email} onChange={(e) => updateOrg('email', e.target.value)} placeholder="admin@hospital.com" />
                <Select label="Timezone" value={data.organization.timezone} onChange={(e) => updateOrg('timezone', e.target.value)}>
                  <option value="Asia/Kathmandu">Asia/Kathmandu (Nepal)</option>
                  <option value="Asia/Kolkata">Asia/Kolkata (India)</option>
                  <option value="Asia/Dhaka">Asia/Dhaka (Bangladesh)</option>
                </Select>
                <Select label="Currency" value={data.organization.currency} onChange={(e) => updateOrg('currency', e.target.value)}>
                  <option value="NPR">NPR — Nepalese Rupee</option>
                  <option value="INR">INR — Indian Rupee</option>
                  <option value="BDT">BDT — Bangladeshi Taka</option>
                  <option value="USD">USD — US Dollar</option>
                </Select>
              </div>
            </div>
          )}

          {step === 1 && (
            <div className="onboarding__form">
              <h2 className="onboarding__form-title">Facility details</h2>
              <p className="onboarding__form-desc">The physical hospital facility you are configuring.</p>
              <div className="onboarding__fields">
                <Input label="Facility name" value={data.facility.name} onChange={(e) => updateFac('name', e.target.value)} required placeholder="e.g. Nepal Medical Center — Main Campus" />
                <Input label="Facility code" value={data.facility.code} onChange={(e) => updateFac('code', e.target.value)} placeholder="Auto-generated if empty" />
                <Input label="Address" value={data.facility.address} onChange={(e) => updateFac('address', e.target.value)} placeholder="Street address" />
                <Input label="City" value={data.facility.city} onChange={(e) => updateFac('city', e.target.value)} placeholder="e.g. Kathmandu" />
                <Input label="Phone" value={data.facility.phone} onChange={(e) => updateFac('phone', e.target.value)} placeholder="+977-…" />
              </div>
            </div>
          )}

          {step === 2 && (
            <div className="onboarding__form">
              <h2 className="onboarding__form-title">Departments</h2>
              <p className="onboarding__form-desc">Select the departments that exist at this facility. You can add more later.</p>
              <div className="onboarding__dept-grid">
                {DEPARTMENT_TEMPLATES.map((dept) => (
                  <button
                    key={dept.code}
                    type="button"
                    className={`onboarding__dept ${data.departments.includes(dept.code) ? 'onboarding__dept--selected' : ''}`}
                    onClick={() => toggleDept(dept.code)}
                  >
                    <span className="onboarding__dept-icon"><dept.icon size={18} /></span>
                    <span className="onboarding__dept-name">{dept.name}</span>
                    <span className="onboarding__dept-category">{dept.category.replace(/_/g, ' ')}</span>
                  </button>
                ))}
              </div>
              <p className="onboarding__form-hint">{data.departments.length} department{data.departments.length !== 1 ? 's' : ''} selected</p>
            </div>
          )}

          {step === 3 && (
            <div className="onboarding__form">
              <h2 className="onboarding__form-title">Modules</h2>
              <p className="onboarding__form-desc">Select the operational modules to enable. Patient Management is required.</p>
              <div className="onboarding__module-grid">
                {AVAILABLE_MODULES.map((mod) => (
                  <button
                    key={mod.code}
                    type="button"
                    className={`onboarding__module ${data.modules.includes(mod.code) ? 'onboarding__module--selected' : ''} ${mod.required ? 'onboarding__module--required' : ''}`}
                    onClick={() => !mod.required && toggleModule(mod.code)}
                    disabled={mod.required}
                  >
                    <mod.icon size={20} strokeWidth={1.5} />
                    <div className="onboarding__module-info">
                      <span className="onboarding__module-name">{mod.name}</span>
                      <span className="onboarding__module-desc">{mod.description}</span>
                    </div>
                    {mod.required && <span className="onboarding__module-badge">Required</span>}
                  </button>
                ))}
              </div>
              <p className="onboarding__form-hint">{data.modules.length} module{data.modules.length !== 1 ? 's' : ''} enabled</p>
            </div>
          )}

          {step === 4 && (
            <div className="onboarding__form">
              <h2 className="onboarding__form-title">Review & activate</h2>
              <p className="onboarding__form-desc">Review your configuration before activating the hospital.</p>

              <div className="onboarding__review">
                <div className="onboarding__review-section">
                  <h3>Organization</h3>
                  <dl className="onboarding__review-list">
                    <div><dt>Name</dt><dd>{data.organization.name || '—'}</dd></div>
                    <div><dt>City</dt><dd>{data.organization.city || '—'}</dd></div>
                    <div><dt>Timezone</dt><dd>{data.organization.timezone}</dd></div>
                    <div><dt>Currency</dt><dd>{data.organization.currency}</dd></div>
                  </dl>
                </div>

                <div className="onboarding__review-section">
                  <h3>Facility</h3>
                  <dl className="onboarding__review-list">
                    <div><dt>Name</dt><dd>{data.facility.name || '—'}</dd></div>
                    <div><dt>City</dt><dd>{data.facility.city || '—'}</dd></div>
                  </dl>
                </div>

                <div className="onboarding__review-section">
                  <h3>Departments ({data.departments.length})</h3>
                  <div className="onboarding__review-tags">
                    {data.departments.map((code) => {
                      const dept = DEPARTMENT_TEMPLATES.find((d) => d.code === code);
                      return <span key={code} className="onboarding__tag">{dept?.name ?? code}</span>;
                    })}
                  </div>
                </div>

                <div className="onboarding__review-section">
                  <h3>Modules ({data.modules.length})</h3>
                  <div className="onboarding__review-tags">
                    {data.modules.map((code) => {
                      const mod = AVAILABLE_MODULES.find((m) => m.code === code);
                      return <span key={code} className="onboarding__tag">{mod?.name ?? code}</span>;
                    })}
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Navigation */}
        <div className="onboarding__footer">
          {step > 0 && (
            <Button variant="ghost" onClick={goBack}>
              <ChevronLeft size={16} /> Back
            </Button>
          )}
          <div className="onboarding__footer-right">
            {step < STEPS.length - 1 ? (
              <Button onClick={goNext} disabled={!canProceed()}>
                Continue <ChevronRight size={16} />
              </Button>
            ) : (
              <Button onClick={() => void activate()} loading={loading} disabled={loading}>
                <CheckCircle size={16} /> Activate hospital
              </Button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
