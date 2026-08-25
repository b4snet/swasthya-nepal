import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { ApiError, api } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/interop.css';

/* ── API Client ──────────────────────────────────────────────────── */

const opt = (facilityId?: string | null) => ({ facilityId } as Record<string, unknown>);

const interopApi = {
  integrations: (fac?: string | null) =>
    api.request<{ integrations: unknown[] }>(`/api/v1/interop/integrations`, opt(fac)),
  partners: (fac?: string | null) =>
    api.request<{ partners: unknown[] }>(`/api/v1/interop/partners`, opt(fac)),
  egressAllowlist: (fac?: string | null) =>
    api.request<{ destinations: unknown[] }>(`/api/v1/interop/egress-allowlist`, opt(fac)),
  registerIntegration: (payload: Record<string, unknown>) =>
    api.request<unknown>('/api/v1/interop/integrations', { method: 'POST', body: payload }),
  recordStatus: (integrationId: string, payload: Record<string, unknown>) =>
    api.request<unknown>(`/api/v1/interop/integrations/${integrationId}/status`, { method: 'POST', body: payload }),
  setKillSwitch: (integrationId: string, enabled: boolean) =>
    api.request<unknown>(`/api/v1/interop/integrations/${integrationId}/kill-switch`, { method: 'POST', body: { enabled } }),
  registerPartner: (payload: Record<string, unknown>) =>
    api.request<unknown>('/api/v1/interop/partners', { method: 'POST', body: payload }),
  revokePartner: (partnerId: string) =>
    api.request<unknown>(`/api/v1/interop/partners/${partnerId}/revoke`, { method: 'POST', body: {} }),
  registerEgress: (payload: Record<string, unknown>) =>
    api.request<unknown>('/api/v1/interop/egress-allowlist', { method: 'POST', body: payload }),
};

/* ── Types ───────────────────────────────────────────────────────── */

interface Integration {
  id: string;
  code: string;
  name: string;
  integrationType: string;
  standards: string[];
  status: string;
  lastCheckedAt: string | null;
  killSwitchEnabled: boolean;
}

interface Partner {
  id: string;
  name: string;
  clientName: string;
  status: string;
  scopes: string[];
  createdAt: string;
}

interface EgressEntry {
  id: string;
  destinationUrl: string;
  description: string | null;
  allowedMethods: string[];
  status: string;
}

/* ── Constants ───────────────────────────────────────────────────── */

const INT_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  configured: { label: 'Configured', color: '#6b7280', bg: '#f3f4f6' },
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  degraded: { label: 'Degraded', color: '#f59e0b', bg: '#fef3c7' },
  disabled: { label: 'Disabled', color: '#ef4444', bg: '#fee2e2' },
};

const STANDARD_COLORS: Record<string, string> = {
  FHIR: '#3b82f6',
  HL7: '#8b5cf6',
  DICOM: '#06b6d4',
  DICOMweb: '#0891b2',
  CDA: '#ec4899',
  X12: '#f59e0b',
};

function StatusBadge({ status, config }: { status: string; config: Record<string, { label: string; color: string; bg: string }> }) {
  const c = config[status] ?? { label: status.replace(/_/g, ' '), color: '#6b7280', bg: '#f3f4f6' };
  return <span className="io-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function InteropPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'integrations' | 'fhir' | 'partners' | 'egress' | 'events'>('integrations');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Create form
  const [createForm, setCreateForm] = useState({ code: '', name: '', integrationType: 'fhir', standards: '' });
  const [partnerForm, setPartnerForm] = useState({ name: '', clientName: '', scopes: 'patient.read,encounter.read' });
  const [egressForm, setEgressForm] = useState({ destinationUrl: '', description: '' });

  // Data fetching
  const integrations = useFetch(
    () => interopApi.integrations(fac).catch(() => ({ integrations: [] })),
    [fac],
  );

  const partners = useFetch(
    () => interopApi.partners(fac).catch(() => ({ partners: [] })),
    [fac],
  );

  const egress = useFetch(
    () => interopApi.egressAllowlist(fac).catch(() => ({ destinations: [] })),
    [fac],
  );

  const allIntegrations = useMemo(() => (integrations.data?.integrations ?? []) as Integration[], [integrations.data]);
  const allPartners = useMemo(() => (partners.data?.partners ?? []) as Partner[], [partners.data]);
  const allEgress = useMemo(() => (egress.data?.destinations ?? []) as EgressEntry[], [egress.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Census
  const activeIntegrations = allIntegrations.filter(i => i.status === 'active').length;
  const degradedIntegrations = allIntegrations.filter(i => i.status === 'degraded').length;
  const killSwitched = allIntegrations.filter(i => i.killSwitchEnabled).length;

  const handleCreateIntegration = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!createForm.code || !createForm.name) return;
    await go(() => interopApi.registerIntegration({
      code: createForm.code,
      name: createForm.name,
      integrationType: createForm.integrationType,
      standards: createForm.standards ? createForm.standards.split(',').map(s => s.trim()) : [],
    }));
    setDlg(null);
    setCreateForm({ code: '', name: '', integrationType: 'fhir', standards: '' });
    integrations.refresh();
  }, [createForm, go, integrations]);

  const handleRegisterPartner = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!partnerForm.name || !partnerForm.clientName) return;
    await go(() => interopApi.registerPartner({
      name: partnerForm.name,
      clientName: partnerForm.clientName,
      scopes: partnerForm.scopes.split(',').map(s => s.trim()),
    }));
    setDlg(null);
    setPartnerForm({ name: '', clientName: '', scopes: 'patient.read,encounter.read' });
    partners.refresh();
  }, [partnerForm, go, partners]);

  const handleRegisterEgress = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!egressForm.destinationUrl) return;
    await go(() => interopApi.registerEgress({
      destinationUrl: egressForm.destinationUrl,
      description: egressForm.description || undefined,
    }));
    setDlg(null);
    setEgressForm({ destinationUrl: '', description: '' });
    egress.refresh();
  }, [egressForm, go, egress]);

  const handleToggleKillSwitch = useCallback(async (id: string, current: boolean) => {
    await go(() => interopApi.setKillSwitch(id, !current));
    integrations.refresh();
  }, [go, integrations]);

  const handleRevokePartner = useCallback(async (id: string) => {
    await go(() => interopApi.revokePartner(id));
    partners.refresh();
  }, [go, partners]);

  return (
    <div className="page io-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Interoperability</h1>
          <p className="page__subtitle">FHIR, HL7, DICOM, integrations, partners, egress control</p>
        </div>
        <div className="io-actions">
          <Button variant="ghost" onClick={() => { integrations.refresh(); partners.refresh(); egress.refresh(); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="io-census">
        <div className="io-census-card io-census-card--total">
          <span className="io-census-value">{allIntegrations.length}</span>
          <span className="io-census-label">Integrations</span>
        </div>
        <div className="io-census-card io-census-card--active">
          <span className="io-census-value" style={{ color: '#10b981' }}>{activeIntegrations}</span>
          <span className="io-census-label">Active</span>
        </div>
        <div className="io-census-card io-census-card--degraded">
          <span className="io-census-value" style={{ color: '#f59e0b' }}>{degradedIntegrations}</span>
          <span className="io-census-label">Degraded</span>
        </div>
        <div className="io-census-card io-census-card--killswitch">
          <span className="io-census-value" style={{ color: '#ef4444' }}>{killSwitched}</span>
          <span className="io-census-label">Kill-Switched</span>
        </div>
        <div className="io-census-card io-census-card--partners">
          <span className="io-census-value">{allPartners.length}</span>
          <span className="io-census-label">Partners</span>
        </div>
        <div className="io-census-card io-census-card--egress">
          <span className="io-census-value">{allEgress.length}</span>
          <span className="io-census-label">Egress Destinations</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="io-tabs">
        {(['integrations', 'fhir', 'partners', 'egress', 'events'] as const).map(t => (
          <button key={t} className={`io-tab ${activeTab === t ? 'io-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'integrations' ? 'Integrations' : t === 'fhir' ? 'FHIR Endpoints' : t === 'partners' ? 'Partners' : t === 'egress' ? 'Egress Allowlist' : 'Events'}
          </button>
        ))}
      </div>

      {/* ── Integrations Tab ──────────────────────────────── */}
      {activeTab === 'integrations' && (
        <Card className="io-section-card">
          <div className="io-section-header">
            <h3>Integration Registry</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('create-integration')}>+ Register Integration</Button>
          </div>
          {allIntegrations.length === 0 ? (
            <EmptyState title="No integrations" body="Register external system integrations to track connectivity." />
          ) : (
            <div className="io-table">
              <div className="io-table-header">
                <span>Code</span>
                <span>Name</span>
                <span>Type</span>
                <span>Standards</span>
                <span>Status</span>
                <span>Kill Switch</span>
                <span>Actions</span>
              </div>
              {allIntegrations.map(i => (
                <div key={i.id} className="io-table-row">
                  <span className="io-mono">{i.code}</span>
                  <span className="io-name">{i.name}</span>
                  <span>{i.integrationType}</span>
                  <span className="io-standards">
                    {(i.standards ?? []).map(s => (
                      <span key={s} className="io-standard-tag" style={{ color: STANDARD_COLORS[s] ?? '#6b7280' }}>{s}</span>
                    ))}
                  </span>
                  <StatusBadge status={i.status} config={INT_STATUS} />
                  <span>
                    <button className={`io-kill-btn ${i.killSwitchEnabled ? 'io-kill-btn--active' : ''}`}
                      onClick={() => void handleToggleKillSwitch(i.id, i.killSwitchEnabled)}>
                      {i.killSwitchEnabled ? 'ENABLED' : 'OFF'}
                    </button>
                  </span>
                  <span className="io-table-actions">
                    <Button variant="ghost" size="sm" onClick={() => void interopApi.recordStatus(i.id, { status: 'active' }).then(() => integrations.refresh())}>Check</Button>
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── FHIR Endpoints Tab ────────────────────────────── */}
      {activeTab === 'fhir' && (
        <Card className="io-section-card">
          <div className="io-section-header">
            <h3>FHIR R4 Endpoints</h3>
          </div>
          <div className="io-fhir-info">
            <div className="io-fhir-endpoint-card">
              <h4>Patient</h4>
              <code className="io-fhir-url">GET /api/v1/interop/fhir/Patient/{'{patientId}'}</code>
              <p>Returns FHIR R4 Patient resource for the specified patient.</p>
              <StatusBadge status="active" config={INT_STATUS} />
            </div>
            <div className="io-fhir-endpoint-card">
              <h4>Encounter</h4>
              <code className="io-fhir-url">GET /api/v1/interop/fhir/Encounter/{'{encounterId}'}</code>
              <p>Returns FHIR R4 Encounter resource for the specified encounter.</p>
              <StatusBadge status="active" config={INT_STATUS} />
            </div>
            <div className="io-fhir-endpoint-card">
              <h4>MedicationRequest</h4>
              <code className="io-fhir-url">GET /api/v1/interop/fhir/MedicationRequest/{'{prescriptionId}'}</code>
              <p>Returns FHIR R4 MedicationRequest resource for the specified prescription.</p>
              <StatusBadge status="active" config={INT_STATUS} />
            </div>
            <div className="io-fhir-endpoint-card">
              <h4>DiagnosticReport</h4>
              <code className="io-fhir-url">GET /api/v1/interop/fhir/DiagnosticReport/{'{labOrderId}'}</code>
              <p>Returns FHIR R4 DiagnosticReport resource for the specified lab order.</p>
              <StatusBadge status="active" config={INT_STATUS} />
            </div>
          </div>
          <div className="io-fhir-note">
            <Alert tone="info">
              FHIR endpoints require partner OAuth2 authentication. External systems must be registered as partners with appropriate scopes.
              All FHIR reads are tenant-scoped and audited.
            </Alert>
          </div>
        </Card>
      )}

      {/* ── Partners Tab ──────────────────────────────────── */}
      {activeTab === 'partners' && (
        <Card className="io-section-card">
          <div className="io-section-header">
            <h3>OAuth2 Partners</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('create-partner')}>+ Register Partner</Button>
          </div>
          {allPartners.length === 0 ? (
            <EmptyState title="No partners" body="Register external systems as OAuth2 partners for FHIR access." />
          ) : (
            <div className="io-table">
              <div className="io-table-header">
                <span>Name</span>
                <span>Client</span>
                <span>Scopes</span>
                <span>Status</span>
                <span>Created</span>
                <span>Actions</span>
              </div>
              {allPartners.map(p => (
                <div key={p.id} className="io-table-row">
                  <span className="io-name">{p.name}</span>
                  <span className="io-mono">{p.clientName}</span>
                  <span className="io-scopes">
                    {(p.scopes ?? []).map(s => (
                      <span key={s} className="io-scope-tag">{s}</span>
                    ))}
                  </span>
                  <StatusBadge status={p.status} config={INT_STATUS} />
                  <span>{p.createdAt ? new Date(p.createdAt).toLocaleDateString() : '—'}</span>
                  <span className="io-table-actions">
                    {p.status !== 'revoked' && (
                      <Button variant="ghost" size="sm" onClick={() => void handleRevokePartner(p.id)}>Revoke</Button>
                    )}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Egress Allowlist Tab ──────────────────────────── */}
      {activeTab === 'egress' && (
        <Card className="io-section-card">
          <div className="io-section-header">
            <h3>Egress Allowlist</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('create-egress')}>+ Add Destination</Button>
          </div>
          {allEgress.length === 0 ? (
            <EmptyState title="No egress destinations" body="Add approved outbound destinations to the SSRF allowlist." />
          ) : (
            <div className="io-table">
              <div className="io-table-header">
                <span>Destination URL</span>
                <span>Description</span>
                <span>Methods</span>
                <span>Status</span>
              </div>
              {allEgress.map(e => (
                <div key={e.id} className="io-table-row">
                  <span className="io-mono">{e.destinationUrl}</span>
                  <span>{e.description ?? '—'}</span>
                  <span className="io-scopes">
                    {(e.allowedMethods ?? []).map(m => (
                      <span key={m} className="io-scope-tag">{m}</span>
                    ))}
                  </span>
                  <StatusBadge status={e.status} config={INT_STATUS} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Events Tab ────────────────────────────────────── */}
      {activeTab === 'events' && (
        <Card className="io-section-card">
          <div className="io-section-header">
            <h3>Integration Events</h3>
          </div>
          <EmptyState title="Integration events" body="Message exchange events between SWASTHYA and external systems appear here." />
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* Create Integration Dialog */}
      {dlg === 'create-integration' && (
        <Dialog open onClose={() => setDlg(null)} title="Register Integration" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateIntegration} loading={busy} disabled={!createForm.code || !createForm.name}>Register</Button>
          </>
        }>
          <form onSubmit={handleCreateIntegration} className="io-form">
            <Input label="Code" value={createForm.code} onChange={e => setCreateForm(f => ({ ...f, code: e.target.value }))} placeholder="e.g. lab-lis-01" required />
            <Input label="Name" value={createForm.name} onChange={e => setCreateForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. External LIS System" required />
            <div className="io-form-field">
              <label className="io-label">Integration Type</label>
              <select className="io-input" value={createForm.integrationType} onChange={e => setCreateForm(f => ({ ...f, integrationType: e.target.value }))}>
                <option value="fhir">FHIR</option>
                <option value="hl7">HL7</option>
                <option value="dicom">DICOM</option>
                <option value="custom">Custom</option>
              </select>
            </div>
            <Input label="Standards (comma-separated)" value={createForm.standards} onChange={e => setCreateForm(f => ({ ...f, standards: e.target.value }))} placeholder="e.g. FHIR, HL7, DICOM" />
          </form>
        </Dialog>
      )}

      {/* Create Partner Dialog */}
      {dlg === 'create-partner' && (
        <Dialog open onClose={() => setDlg(null)} title="Register OAuth2 Partner" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleRegisterPartner} loading={busy} disabled={!partnerForm.name || !partnerForm.clientName}>Register</Button>
          </>
        }>
          <form onSubmit={handleRegisterPartner} className="io-form">
            <Input label="Partner Name" value={partnerForm.name} onChange={e => setPartnerForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. District Hospital" required />
            <Input label="Client Name" value={partnerForm.clientName} onChange={e => setPartnerForm(f => ({ ...f, clientName: e.target.value }))} placeholder="e.g. district-hospital-lis" required />
            <Input label="Scopes (comma-separated)" value={partnerForm.scopes} onChange={e => setPartnerForm(f => ({ ...f, scopes: e.target.value }))} placeholder="e.g. patient.read,encounter.read" />
            <Alert tone="info">Partners receive OAuth2 client_credentials tokens scoped to the specified permissions. All access is audited.</Alert>
          </form>
        </Dialog>
      )}

      {/* Create Egress Dialog */}
      {dlg === 'create-egress' && (
        <Dialog open onClose={() => setDlg(null)} title="Add Egress Destination" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleRegisterEgress} loading={busy} disabled={!egressForm.destinationUrl}>Add</Button>
          </>
        }>
          <form onSubmit={handleRegisterEgress} className="io-form">
            <Input label="Destination URL" value={egressForm.destinationUrl} onChange={e => setEgressForm(f => ({ ...f, destinationUrl: e.target.value }))} placeholder="e.g. https://lis.hospital.gov.np" required />
            <Input label="Description" value={egressForm.description} onChange={e => setEgressForm(f => ({ ...f, description: e.target.value }))} placeholder="e.g. National LIS endpoint" />
            <Alert tone="warning">Only pre-approved destinations are allowed. Outbound requests to non-listed URLs will be blocked by the SSRF guard.</Alert>
          </form>
        </Dialog>
      )}
    </div>
  );
}
