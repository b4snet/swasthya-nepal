import { useState, useEffect } from 'react';
import { useTenant } from '../../context/TenantContext';
import { hospitalBrandingApi } from '../../api/endpoints';
import { Alert, Button, Card, EmptyState, ErrorState, Input, SkeletonStats } from '../../components/ui';
import { ApiError } from '../../api/client';
import type { HospitalBranding } from '../../api/types';
import './admin-branding.css';

const DATE_FORMATS = [
  { value: 'Y-m-d', label: '2026-08-21' },
  { value: 'd/m/Y', label: '21/08/2026' },
  { value: 'm/d/Y', label: '08/21/2026' },
  { value: 'd-M-Y', label: '21-Aug-2026' },
  { value: 'd-M-Y', label: '21-Aug-2026' },
  { value: 'M d, Y', label: 'Aug 21, 2026' },
  { value: 'jS M Y', label: '21st Aug 2026' },
];

const TIME_FORMATS = [
  { value: 'H:i', label: '24-hour (14:30)' },
  { value: 'h:i A', label: '12-hour (2:30 PM)' },
];

const CURRENCIES = [
  { code: 'NPR', symbol: 'Rs.', name: 'Nepalese Rupee' },
  { code: 'INR', symbol: '₹', name: 'Indian Rupee' },
  { code: 'USD', symbol: '$', name: 'US Dollar' },
  { code: 'GBP', symbol: '£', name: 'British Pound' },
  { code: 'EUR', symbol: '€', name: 'Euro' },
  { code: 'AUD', symbol: 'A$', name: 'Australian Dollar' },
  { code: 'CAD', symbol: 'C$', name: 'Canadian Dollar' },
];

type Section = 'identity' | 'contact' | 'address' | 'documents' | 'financial' | 'legal';

export function AdminBrandingPage() {
  const { selectedFacilityId } = useTenant();
  const [branding, setBranding] = useState<HospitalBranding | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [activeSection, setActiveSection] = useState<Section>('identity');
  const [dirty, setDirty] = useState(false);

  // Form state
  const [form, setForm] = useState({
    hospitalName: '',
    hospitalNameLocal: '',
    logoUrl: '',
    faviconUrl: '',
    primaryColor: '#0891b2',
    secondaryColor: '#1e293b',
    phone: '',
    emergencyPhone: '',
    email: '',
    website: '',
    addressLine1: '',
    addressLine2: '',
    city: '',
    state: '',
    country: 'Nepal',
    postalCode: '',
    documentHeader: '',
    documentFooter: '',
    letterheadText: '',
    dateFormat: 'Y-m-d',
    timeFormat: 'H:i',
    currency: 'NPR',
    currencySymbol: 'Rs.',
    vatRate: 0,
    vatNumber: '',
    registrationNumber: '',
    panNumber: '',
    termsAndConditions: '',
    privacyPolicy: '',
  });

  useEffect(() => {
    if (!selectedFacilityId) { setLoading(false); return; }
    setLoading(true);
    hospitalBrandingApi.get(selectedFacilityId)
      .then(res => {
        const data = res as unknown as { branding: HospitalBranding | null; defaults: Record<string, unknown> };
        if (data.branding) {
          setBranding(data.branding);
          setForm({
            hospitalName: data.branding.hospitalName ?? '',
            hospitalNameLocal: data.branding.hospitalNameLocal ?? '',
            logoUrl: data.branding.logoUrl ?? '',
            faviconUrl: data.branding.faviconUrl ?? '',
            primaryColor: data.branding.primaryColor ?? '#0891b2',
            secondaryColor: data.branding.secondaryColor ?? '#1e293b',
            phone: data.branding.phone ?? '',
            emergencyPhone: data.branding.emergencyPhone ?? '',
            email: data.branding.email ?? '',
            website: data.branding.website ?? '',
            addressLine1: data.branding.addressLine1 ?? '',
            addressLine2: data.branding.addressLine2 ?? '',
            city: data.branding.city ?? '',
            state: data.branding.state ?? '',
            country: data.branding.country ?? 'Nepal',
            postalCode: data.branding.postalCode ?? '',
            documentHeader: data.branding.documentHeader ?? '',
            documentFooter: data.branding.documentFooter ?? '',
            letterheadText: data.branding.letterheadText ?? '',
            dateFormat: data.branding.dateFormat ?? 'Y-m-d',
            timeFormat: data.branding.timeFormat ?? 'H:i',
            currency: data.branding.currency ?? 'NPR',
            currencySymbol: data.branding.currencySymbol ?? 'Rs.',
            vatRate: data.branding.vatRate ?? 0,
            vatNumber: data.branding.vatNumber ?? '',
            registrationNumber: data.branding.registrationNumber ?? '',
            panNumber: data.branding.panNumber ?? '',
            termsAndConditions: data.branding.termsAndConditions ?? '',
            privacyPolicy: data.branding.privacyPolicy ?? '',
          });
        }
        setLoading(false);
      })
      .catch(err => { setError(err instanceof ApiError ? err.message : 'Failed to load branding'); setLoading(false); });
  }, [selectedFacilityId]);

  const update = (key: string, value: string | number) => {
    setForm(prev => ({ ...prev, [key]: value }));
    setDirty(true);
    setSaveSuccess(false);
  };

  const handleCurrencyChange = (code: string) => {
    const curr = CURRENCIES.find(c => c.code === code);
    if (curr) {
      setForm(prev => ({ ...prev, currency: curr.code, currencySymbol: curr.symbol }));
      setDirty(true);
    }
  };

  const handleSave = async () => {
    if (!selectedFacilityId) return;
    setSaving(true);
    setError(null);
    try {
      const res = await hospitalBrandingApi.update(selectedFacilityId, form as unknown as Partial<HospitalBranding>);
      const data = res as unknown as { branding: HospitalBranding };
      setBranding(data.branding);
      setDirty(false);
      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 3000);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to save branding');
    } finally {
      setSaving(false);
    }
  };

  if (!selectedFacilityId) {
    return <EmptyState title="Select a facility" body="Choose a facility to configure its branding and document settings." />;
  }

  if (loading) {
    return (
      <div className="stack">
        <div className="page__head"><h2>Hospital Branding</h2></div>
        <SkeletonStats />
      </div>
    );
  }

  if (error && !branding) {
    return <ErrorState error={error} onRetry={() => void window.location.reload()} />;
  }

  const sections: { key: Section; label: string; icon: string }[] = [
    { key: 'identity', label: 'Identity', icon: '⊞' },
    { key: 'contact', label: 'Contact', icon: '☎' },
    { key: 'address', label: 'Address', icon: '◈' },
    { key: 'documents', label: 'Documents', icon: '▤' },
    { key: 'financial', label: 'Financial', icon: '₹' },
    { key: 'legal', label: 'Legal', icon: '§' },
  ];

  return (
    <div className="branding-page">
      <div className="branding-page__head">
        <div className="branding-page__head-info">
          <h2>Hospital Branding &amp; Document Configuration</h2>
          <p className="text-muted">Configure how your hospital appears on forms, documents, invoices, and reports.</p>
        </div>
        <div className="branding-page__head-actions">
          {saveSuccess && <span className="branding-saved">✓ Saved</span>}
          {dirty && <span className="branding-dirty">Unsaved changes</span>}
          <Button onClick={() => void handleSave()} loading={saving} disabled={!dirty}>
            Save Changes
          </Button>
        </div>
      </div>

      {error && <Alert tone="danger">{error}</Alert>}

      <div className="branding-layout">
        {/* Sidebar nav */}
        <nav className="branding-nav">
          {sections.map(s => (
            <button
              key={s.key}
              className={`branding-nav__item ${activeSection === s.key ? 'branding-nav__item--active' : ''}`}
              onClick={() => setActiveSection(s.key)}
            >
              <span className="branding-nav__icon">{s.icon}</span>
              {s.label}
            </button>
          ))}
        </nav>

        {/* Content */}
        <div className="branding-content">
          {activeSection === 'identity' && (
            <Card className="branding-card">
              <h3 className="branding-card__title">Hospital Identity</h3>
              <p className="branding-card__desc">Configure the hospital name, logo, and brand colors used across the application and printed documents.</p>
              <div className="branding-form-grid">
                <Input label="Hospital Name" value={form.hospitalName} onChange={e => update('hospitalName', e.target.value)} placeholder="e.g. Swasthya Medical Center" className="branding-input--full" />
                <Input label="Name in Local Language" value={form.hospitalNameLocal} onChange={e => update('hospitalNameLocal', e.target.value)} placeholder="e.g. स्वास्थ्य मेडिकल सेन्टर" className="branding-input--full" />
                <Input label="Logo URL" value={form.logoUrl} onChange={e => update('logoUrl', e.target.value)} placeholder="https://..." hint="Full URL to the hospital logo image" className="branding-input--full" />
                <Input label="Favicon URL" value={form.faviconUrl} onChange={e => update('faviconUrl', e.target.value)} placeholder="https://..." className="branding-input--full" />
                <div className="branding-color-row">
                  <div className="branding-color-field">
                    <label>Primary Color</label>
                    <div className="branding-color-picker">
                      <input type="color" value={form.primaryColor} onChange={e => update('primaryColor', e.target.value)} />
                      <Input label="Primary" value={form.primaryColor} onChange={e => update('primaryColor', e.target.value)} placeholder="#0891b2" />
                    </div>
                  </div>
                  <div className="branding-color-field">
                    <label>Secondary Color</label>
                    <div className="branding-color-picker">
                      <input type="color" value={form.secondaryColor} onChange={e => update('secondaryColor', e.target.value)} />
                      <Input label="Secondary" value={form.secondaryColor} onChange={e => update('secondaryColor', e.target.value)} placeholder="#1e293b" />
                    </div>
                  </div>
                </div>
              </div>

              {/* Live preview */}
              <div className="branding-preview">
                <h4>Brand Preview</h4>
                <div className="branding-preview__card" style={{ borderTopColor: form.primaryColor }}>
                  <div className="branding-preview__header" style={{ background: form.primaryColor }}>
                    <span className="branding-preview__name">{form.hospitalName || 'Hospital Name'}</span>
                  </div>
                  <div className="branding-preview__body">
                    <span style={{ color: form.secondaryColor }}>{form.hospitalNameLocal || 'स्वास्थ्य'}</span>
                  </div>
                </div>
              </div>
            </Card>
          )}

          {activeSection === 'contact' && (
            <Card className="branding-card">
              <h3 className="branding-card__title">Contact Information</h3>
              <p className="branding-card__desc">Phone numbers, email, and website displayed on documents and in the application.</p>
              <div className="branding-form-grid">
                <Input label="Phone" value={form.phone} onChange={e => update('phone', e.target.value)} placeholder="+977-1-XXXXXXX" />
                <Input label="Emergency Phone" value={form.emergencyPhone} onChange={e => update('emergencyPhone', e.target.value)} placeholder="+977-1-XXXXXXX" hint="Displayed on emergency documents" />
                <Input label="Email" value={form.email} onChange={e => update('email', e.target.value)} placeholder="info@hospital.com" type="email" />
                <Input label="Website" value={form.website} onChange={e => update('website', e.target.value)} placeholder="https://hospital.com" />
              </div>
            </Card>
          )}

          {activeSection === 'address' && (
            <Card className="branding-card">
              <h3 className="branding-card__title">Address</h3>
              <p className="branding-card__desc">Hospital address used on letterheads, invoices, and official documents.</p>
              <div className="branding-form-grid">
                <Input label="Address Line 1" value={form.addressLine1} onChange={e => update('addressLine1', e.target.value)} placeholder="Street address" className="branding-input--full" />
                <Input label="Address Line 2" value={form.addressLine2} onChange={e => update('addressLine2', e.target.value)} placeholder="Building, floor, etc." className="branding-input--full" />
                <Input label="City" value={form.city} onChange={e => update('city', e.target.value)} placeholder="Kathmandu" />
                <Input label="State / Province" value={form.state} onChange={e => update('state', e.target.value)} placeholder="Bagmati" />
                <Input label="Country" value={form.country} onChange={e => update('country', e.target.value)} placeholder="Nepal" />
                <Input label="Postal Code" value={form.postalCode} onChange={e => update('postalCode', e.target.value)} placeholder="44600" />
              </div>
            </Card>
          )}

          {activeSection === 'documents' && (
            <Card className="branding-card">
              <h3 className="branding-card__title">Document Configuration</h3>
              <p className="branding-card__desc">Header, footer, letterhead, and formatting settings for printed documents, PDFs, forms, invoices, and discharge summaries.</p>
              <div className="branding-form-grid">
                <div className="branding-input--full">
                  <label className="form-label">Document Header</label>
                  <textarea
                    className="form-textarea branding-textarea"
                    value={form.documentHeader}
                    onChange={e => update('documentHeader', e.target.value)}
                    placeholder="Text displayed at the top of all printed documents"
                    rows={3}
                  />
                  <span className="form-hint">Appears on forms, invoices, reports, and discharge documents</span>
                </div>
                <div className="branding-input--full">
                  <label className="form-label">Document Footer</label>
                  <textarea
                    className="form-textarea branding-textarea"
                    value={form.documentFooter}
                    onChange={e => update('documentFooter', e.target.value)}
                    placeholder="Text displayed at the bottom of all printed documents"
                    rows={3}
                  />
                  <span className="form-hint">Common footer for all printed output</span>
                </div>
                <div className="branding-input--full">
                  <label className="form-label">Letterhead Text</label>
                  <textarea
                    className="form-textarea branding-textarea"
                    value={form.letterheadText}
                    onChange={e => update('letterheadText', e.target.value)}
                    placeholder="Formal letterhead content for official correspondence"
                    rows={4}
                  />
                  <span className="form-hint">Used on referral letters, certificates, and official correspondence</span>
                </div>
                <div>
                  <label className="form-label">Date Format</label>
                  <select className="form-select" value={form.dateFormat} onChange={e => update('dateFormat', e.target.value)}>
                    {DATE_FORMATS.map(d => <option key={d.value} value={d.value}>{d.label}</option>)}
                  </select>
                </div>
                <div>
                  <label className="form-label">Time Format</label>
                  <select className="form-select" value={form.timeFormat} onChange={e => update('timeFormat', e.target.value)}>
                    {TIME_FORMATS.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                  </select>
                </div>
              </div>

              {/* Document preview */}
              <div className="branding-doc-preview">
                <h4>Document Preview</h4>
                <div className="branding-doc-preview__sheet">
                  <div className="branding-doc-preview__header" style={{ borderBottomColor: form.primaryColor }}>
                    <strong>{form.hospitalName || 'Hospital Name'}</strong>
                    {form.documentHeader && <p className="branding-doc-preview__subheader">{form.documentHeader}</p>}
                  </div>
                  <div className="branding-doc-preview__body">
                    <p className="branding-doc-preview__placeholder">Document content appears here...</p>
                  </div>
                  <div className="branding-doc-preview__footer" style={{ borderTopColor: form.primaryColor }}>
                    {form.documentFooter || 'Footer text appears here'}
                  </div>
                </div>
              </div>
            </Card>
          )}

          {activeSection === 'financial' && (
            <Card className="branding-card">
              <h3 className="branding-card__title">Financial Configuration</h3>
              <p className="branding-card__desc">Currency, tax, and identification settings for invoices, receipts, and billing documents.</p>
              <div className="branding-form-grid">
                <div>
                  <label className="form-label">Currency</label>
                  <select className="form-select" value={form.currency} onChange={e => handleCurrencyChange(e.target.value)}>
                    {CURRENCIES.map(c => <option key={c.code} value={c.code}>{c.code} — {c.name}</option>)}
                  </select>
                </div>
                <Input label="Currency Symbol" value={form.currencySymbol} onChange={e => update('currencySymbol', e.target.value)} placeholder="Rs." />
                <Input label="VAT / Tax Rate (%)" value={form.vatRate} onChange={e => update('vatRate', parseFloat(e.target.value) || 0)} type="number" min="0" max="100" step="0.5" />
                <Input label="VAT / Tax Number" value={form.vatNumber} onChange={e => update('vatNumber', e.target.value)} placeholder="VAT registration number" />
                <Input label="Hospital Registration / License No." value={form.registrationNumber} onChange={e => update('registrationNumber', e.target.value)} placeholder="Official registration number" />
                <Input label="PAN Number" value={form.panNumber} onChange={e => update('panNumber', e.target.value)} placeholder="Tax PAN number" />
              </div>

              {/* Financial preview */}
              <div className="branding-invoice-preview">
                <h4>Invoice Preview</h4>
                <div className="branding-invoice-preview__sheet">
                  <div className="branding-invoice-preview__header" style={{ background: form.primaryColor }}>
                    <strong>{form.hospitalName || 'Hospital Name'}</strong>
                    {form.vatNumber && <span>VAT: {form.vatNumber}</span>}
                  </div>
                  <div className="branding-invoice-preview__row">
                    <span>Consultation Fee</span>
                    <span>{form.currencySymbol} 1,000.00</span>
                  </div>
                  <div className="branding-invoice-preview__row">
                    <span>Laboratory</span>
                    <span>{form.currencySymbol} 500.00</span>
                  </div>
                  {form.vatRate > 0 && (
                    <div className="branding-invoice-preview__row branding-invoice-preview__row--tax">
                      <span>VAT ({form.vatRate}%)</span>
                      <span>{form.currencySymbol} {(1500 * form.vatRate / 100).toFixed(2)}</span>
                    </div>
                  )}
                  <div className="branding-invoice-preview__row branding-invoice-preview__row--total" style={{ borderTopColor: form.primaryColor }}>
                    <strong>Total</strong>
                    <strong>{form.currencySymbol} {(1500 * (1 + form.vatRate / 100)).toFixed(2)}</strong>
                  </div>
                </div>
              </div>
            </Card>
          )}

          {activeSection === 'legal' && (
            <Card className="branding-card">
              <h3 className="branding-card__title">Legal &amp; Terms</h3>
              <p className="branding-card__desc">Terms, conditions, and privacy policy displayed on patient-facing forms and the patient portal.</p>
              <div className="branding-form-grid">
                <div className="branding-input--full">
                  <label className="form-label">Terms and Conditions</label>
                  <textarea
                    className="form-textarea branding-textarea"
                    value={form.termsAndConditions}
                    onChange={e => update('termsAndConditions', e.target.value)}
                    placeholder="Terms and conditions displayed on patient consent forms and registration"
                    rows={6}
                  />
                </div>
                <div className="branding-input--full">
                  <label className="form-label">Privacy Policy</label>
                  <textarea
                    className="form-textarea branding-textarea"
                    value={form.privacyPolicy}
                    onChange={e => update('privacyPolicy', e.target.value)}
                    placeholder="Privacy policy for patient data handling"
                    rows={6}
                  />
                </div>
              </div>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
