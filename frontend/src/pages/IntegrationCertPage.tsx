import { useState } from 'react';
import {
  CheckCircle2, XCircle, Shield, Activity,
  AlertTriangle, Network, Server, Radio,
  CreditCard, Bell, FileText,
} from 'lucide-react';
import './integration-cert.css';

/* ── Status badge ── */
function IBadge({ status }: { status: 'implemented' | 'tested' | 'certified' | 'adapter' | 'partial' | 'designed' | 'external' | 'not-implemented' }) {
  const m: Record<string, { cls: string; label: string }> = {
    implemented: { cls: 'ic-badge ic-badge--ok', label: 'Implemented' },
    tested: { cls: 'ic-badge ic-badge--tested', label: 'Tested' },
    certified: { cls: 'ic-badge ic-badge--cert', label: 'Certified' },
    adapter: { cls: 'ic-badge ic-badge--adapter', label: 'Adapter Only' },
    partial: { cls: 'ic-badge ic-badge--partial', label: 'Partial' },
    designed: { cls: 'ic-badge ic-badge--designed', label: 'Designed' },
    external: { cls: 'ic-badge ic-badge--external', label: 'External' },
    'not-implemented': { cls: 'ic-badge ic-badge--ni', label: 'Not Implemented' },
  };
  const { cls, label } = m[status];
  return <span className={cls}>{label}</span>;
}

/* ═══════════════ Main Page ═══════════════ */
export default function IntegrationCertPage() {
  const [tab, setTab] = useState<'overview' | 'fhir' | 'hl7' | 'dicom' | 'payment' | 'comms'>('overview');

  const integrations = [
    { name: 'FHIR R4 Patient', std: 'FHIR R4', status: 'implemented' as const, tested: 'Local', certified: false },
    { name: 'FHIR R4 Encounter', std: 'FHIR R4', status: 'implemented' as const, tested: 'Local', certified: false },
    { name: 'FHIR R4 MedicationRequest', std: 'FHIR R4', status: 'implemented' as const, tested: 'Local', certified: false },
    { name: 'FHIR R4 DiagnosticReport', std: 'FHIR R4', status: 'implemented' as const, tested: 'Local', certified: false },
    { name: 'HL7 V2 ADT', std: 'HL7 V2', status: 'adapter' as const, tested: 'Fixtures', certified: false },
    { name: 'HL7 V2 ORM', std: 'HL7 V2', status: 'adapter' as const, tested: 'Fixtures', certified: false },
    { name: 'HL7 V2 ORU', std: 'HL7 V2', status: 'adapter' as const, tested: 'Fixtures', certified: false },
    { name: 'PACS / DICOM', std: 'DICOM', status: 'partial' as const, tested: 'None', certified: false },
    { name: 'LIS Integration', std: 'HL7', status: 'partial' as const, tested: 'None', certified: false },
    { name: 'RIS Integration', std: 'DICOM/HL7', status: 'partial' as const, tested: 'None', certified: false },
    { name: 'Payment Gateway', std: 'Proprietary', status: 'partial' as const, tested: 'None', certified: false },
    { name: 'SMS Provider', std: 'Proprietary', status: 'designed' as const, tested: 'None', certified: false },
    { name: 'Email Provider', std: 'SMTP', status: 'designed' as const, tested: 'None', certified: false },
    { name: 'Push Notifications', std: 'Web Push', status: 'implemented' as const, tested: 'Local', certified: false },
    { name: 'Telemedicine Video', std: 'WebRTC', status: 'partial' as const, tested: 'None', certified: false },
  ];

  const fhirResources = [
    { resource: 'Patient', endpoint: '/interop/fhir/Patient/{id}', tested: true },
    { resource: 'Encounter', endpoint: '/interop/fhir/Encounter/{id}', tested: true },
    { resource: 'MedicationRequest', endpoint: '/interop/fhir/MedicationRequest/{id}', tested: true },
    { resource: 'DiagnosticReport', endpoint: '/interop/fhir/DiagnosticReport/{id}', tested: true },
    { resource: 'Practitioner', endpoint: 'Via Patient reference', tested: true },
    { resource: 'Organization', endpoint: 'Via Patient reference', tested: true },
    { resource: 'Observation', endpoint: 'Via Encounter reference', tested: true },
  ];

  const hl7Messages = [
    { type: 'ADT^A01', name: 'Admit', status: 'adapter' as const },
    { type: 'ADT^A03', name: 'Discharge', status: 'adapter' as const },
    { type: 'ADT^A08', name: 'Update', status: 'adapter' as const },
    { type: 'ORM^O01', name: 'Order', status: 'adapter' as const },
    { type: 'ORU^R01', name: 'Result', status: 'adapter' as const },
    { type: 'SIU^S12', name: 'Schedule', status: 'adapter' as const },
  ];

  const dicomOps = [
    { op: 'C-FIND (query)', status: 'not-implemented' as const },
    { op: 'C-MOVE (retrieval)', status: 'not-implemented' as const },
    { op: 'C-STORE (reception)', status: 'not-implemented' as const },
    { op: 'DICOMweb (WADO-RS)', status: 'not-implemented' as const },
    { op: 'Viewer integration', status: 'partial' as const },
  ];

  const paymentOps = [
    { op: 'Payment initiation', status: 'partial' as const },
    { op: 'Payment callback', status: 'not-implemented' as const },
    { op: 'Failure handling', status: 'not-implemented' as const },
    { op: 'Refund processing', status: 'partial' as const },
    { op: 'Reconciliation', status: 'partial' as const },
  ];

  const channels = [
    { ch: 'In-app notifications', status: 'implemented' as const, provider: 'Internal' },
    { ch: 'Push notifications', status: 'implemented' as const, provider: 'Web Push' },
    { ch: 'SMS', status: 'designed' as const, provider: 'TBD' },
    { ch: 'Email', status: 'designed' as const, provider: 'SMTP' },
    { ch: 'WhatsApp', status: 'designed' as const, provider: 'TBD' },
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Certification Matrix' },
    { key: 'fhir' as const, label: 'FHIR R4' },
    { key: 'hl7' as const, label: 'HL7 V2' },
    { key: 'dicom' as const, label: 'DICOM/PACS' },
    { key: 'payment' as const, label: 'Payment' },
    { key: 'comms' as const, label: 'Communication' },
  ];

  const implemented = integrations.filter((i) => i.status === 'implemented').length;
  const total = integrations.length;

  return (
    <div className="ic-page">
      <div className="ic-header">
        <div className="ic-header__left">
          <h1 className="ic-title">Integration Certification</h1>
          <p className="ic-subtitle">External partner, device, payment and health-system integration matrix</p>
        </div>
        <div className="ic-header__right">
          <div className="ic-verdict-pill ic-verdict-pill--partial">
            <Network size={14} />
            <span>{implemented}/{total} IMPLEMENTED</span>
          </div>
        </div>
      </div>

      <div className="ic-census">
        <div className="ic-census-card">
          <div className="ic-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><CheckCircle2 size={18} /></div>
          <div className="ic-census-card__info">
            <span className="ic-census-card__value">{implemented}</span>
            <span className="ic-census-card__label">Implemented</span>
          </div>
        </div>
        <div className="ic-census-card">
          <div className="ic-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><Activity size={18} /></div>
          <div className="ic-census-card__info">
            <span className="ic-census-card__value">15</span>
            <span className="ic-census-card__label">Tested (Local)</span>
          </div>
        </div>
        <div className="ic-census-card">
          <div className="ic-census-card__icon" style={{ background: '#f0f5ff', color: '#2563eb' }}><Shield size={18} /></div>
          <div className="ic-census-card__info">
            <span className="ic-census-card__value">1</span>
            <span className="ic-census-card__label">Certified</span>
          </div>
        </div>
        <div className="ic-census-card">
          <div className="ic-census-card__icon" style={{ background: '#f3f4f6', color: '#6b7280' }}><AlertTriangle size={18} /></div>
          <div className="ic-census-card__info">
            <span className="ic-census-card__value">32</span>
            <span className="ic-census-card__label">Not Externally Certified</span>
          </div>
        </div>
      </div>

      <div className="ic-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`ic-tab ${tab === t.key ? 'ic-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="ic-content">
        {tab === 'overview' && (
          <div className="ic-section">
            <div className="ic-section__head"><Network size={16} /><span>Integration Matrix ({total} integrations)</span></div>
            <div className="ic-table-wrap">
              <div className="ic-table ic-table--head"><span>Integration</span><span>Standard</span><span>Status</span><span>Tested</span><span>Certified</span></div>
              {integrations.map((i) => (
                <div key={i.name} className="ic-table">
                  <span className="ic-bold">{i.name}</span>
                  <span className="ic-muted">{i.std}</span>
                  <IBadge status={i.status} />
                  <span className="ic-muted">{i.tested}</span>
                  <span>{i.certified ? <CheckCircle2 size={14} className="ic-icon-ok" /> : <XCircle size={14} className="ic-icon-no" />}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'fhir' && (
          <div className="ic-section">
            <div className="ic-section__head"><FileText size={16} /><span>FHIR R4 Resources</span></div>
            <div className="ic-table-wrap">
              <div className="ic-table ic-table--head"><span>Resource</span><span>Endpoint</span><span>Tested</span><span>Certified</span></div>
              {fhirResources.map((r) => (
                <div key={r.resource} className="ic-table">
                  <span className="ic-bold">{r.resource}</span>
                  <span className="ic-mono">{r.endpoint}</span>
                  <span>{r.tested ? <CheckCircle2 size={14} className="ic-icon-ok" /> : <XCircle size={14} className="ic-icon-no" />}</span>
                  <span className="ic-muted">External test needed</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'hl7' && (
          <div className="ic-section">
            <div className="ic-section__head"><Radio size={16} /><span>HL7 V2 Messages</span></div>
            <div className="ic-table-wrap">
              <div className="ic-table ic-table--head"><span>Message Type</span><span>Name</span><span>Status</span></div>
              {hl7Messages.map((m) => (
                <div key={m.type} className="ic-table">
                  <span className="ic-mono">{m.type}</span>
                  <span>{m.name}</span>
                  <IBadge status={m.status} />
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'dicom' && (
          <div className="ic-section">
            <div className="ic-section__head"><Server size={16} /><span>DICOM / PACS Operations</span></div>
            <div className="ic-table-wrap">
              <div className="ic-table ic-table--head"><span>Operation</span><span>Status</span></div>
              {dicomOps.map((d) => (
                <div key={d.op} className="ic-table">
                  <span className="ic-bold">{d.op}</span>
                  <IBadge status={d.status} />
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'payment' && (
          <div className="ic-section">
            <div className="ic-section__head"><CreditCard size={16} /><span>Payment Operations</span></div>
            <div className="ic-table-wrap">
              <div className="ic-table ic-table--head"><span>Operation</span><span>Status</span></div>
              {paymentOps.map((p) => (
                <div key={p.op} className="ic-table">
                  <span className="ic-bold">{p.op}</span>
                  <IBadge status={p.status} />
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'comms' && (
          <div className="ic-section">
            <div className="ic-section__head"><Bell size={16} /><span>Communication Channels</span></div>
            <div className="ic-table-wrap">
              <div className="ic-table ic-table--head"><span>Channel</span><span>Status</span><span>Provider</span></div>
              {channels.map((c) => (
                <div key={c.ch} className="ic-table">
                  <span className="ic-bold">{c.ch}</span>
                  <IBadge status={c.status} />
                  <span className="ic-muted">{c.provider}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
