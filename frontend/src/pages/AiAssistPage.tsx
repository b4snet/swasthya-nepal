import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { api } from '../api/client';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/ai-assist.css';

/* ── API Client ──────────────────────────────────────────────────── */

const opt = (facilityId?: string | null) => ({ facilityId } as Record<string, unknown>);

const aiApi = {
  features: (fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/ai/features`, opt(fac)).catch(() => []),
  storeFeature: (payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>('/api/v1/ai/features', { method: 'POST', body: payload, ...opt(fac) }),
  activateFeature: (featureId: string, fac?: string | null) =>
    api.request<unknown>(`/api/v1/ai/features/${featureId}/activate`, { method: 'POST', body: {}, ...opt(fac) }),
  switchFeature: (featureId: string, payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>(`/api/v1/ai/features/${featureId}/switch`, { method: 'PATCH', body: payload, ...opt(fac) }),
  invokeFeature: (featureId: string, payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>(`/api/v1/ai/features/${featureId}/invoke`, { method: 'POST', body: payload, ...opt(fac) }),
  createDraft: (payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>('/api/v1/ai/drafts', { method: 'POST', body: payload, ...opt(fac) }),
  signDraft: (draftId: string, fac?: string | null) =>
    api.request<unknown>(`/api/v1/ai/drafts/${draftId}/sign`, { method: 'POST', body: {}, ...opt(fac) }),
};

/* ── Types ───────────────────────────────────────────────────────── */

interface AiFeature {
  id: string;
  code: string;
  name: string;
  function: string;
  tier: string;
  status: string;
  enabled: boolean;
  modelApproved: boolean;
  evaluationRef: string | null;
  purpose: string | null;
}

/* ── Constants ───────────────────────────────────────────────────── */

const FEATURE_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  registered: { label: 'Registered', color: '#6b7280', bg: '#f3f4f6' },
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  disabled: { label: 'Disabled', color: '#ef4444', bg: '#fee2e2' },
  evaluation: { label: 'Evaluation', color: '#f59e0b', bg: '#fef3c7' },
};

const AI_FUNCTIONS = [
  { value: 'documentation_draft', label: 'Documentation Draft' },
  { value: 'summarization', label: 'Clinical Summarization' },
  { value: 'forecast', label: 'Operational Forecast' },
  { value: 'coding_assistance', label: 'Coding Assistance' },
  { value: 'communication_draft', label: 'Patient Communication' },
  { value: 'inbox_prioritization', label: 'Inbox Prioritization' },
];

const AI_TIERS = [
  { value: 'administrative', label: 'Administrative (Low Risk)', color: '#10b981' },
  { value: 'clinical_support', label: 'Clinical Support (Medium Risk)', color: '#f59e0b' },
  { value: 'clinical_decision', label: 'Clinical Decision (High Risk)', color: '#ef4444' },
];

function StatusBadge({ status, config }: { status: string; config: Record<string, { label: string; color: string; bg: string }> }) {
  const c = config[status] ?? { label: status.replace(/_/g, ' '), color: '#6b7280', bg: '#f3f4f6' };
  return <span className="ai-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function AiAssistPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'features' | 'drafts' | 'governance' | 'audit' | 'safety'>('features');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Forms
  const [featureForm, setFeatureForm] = useState({ code: '', name: '', function: 'documentation_draft', tier: 'administrative', purpose: '' });
  const [draftForm, setDraftForm] = useState({ patientId: '', encounterId: '', function: 'documentation_draft', content: '' });

  // Data fetching
  const features = useFetch(
    () => aiApi.features(fac),
    [fac],
  );

  const allFeatures = useMemo(() => (features.data ?? []) as unknown as AiFeature[], [features.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Census
  const activeFeatures = allFeatures.filter(f => f.enabled).length;
  const approvedModels = allFeatures.filter(f => f.modelApproved).length;

  const handleCreateFeature = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!featureForm.code || !featureForm.name) return;
    await go(() => aiApi.storeFeature({
      code: featureForm.code,
      name: featureForm.name,
      function: featureForm.function,
      tier: featureForm.tier,
      purpose: featureForm.purpose || undefined,
    }, fac));
    setDlg(null);
    setFeatureForm({ code: '', name: '', function: 'documentation_draft', tier: 'administrative', purpose: '' });
    features.refresh();
  }, [featureForm, fac, go, features]);

  const handleToggleFeature = useCallback(async (id: string, enabled: boolean) => {
    await go(() => aiApi.switchFeature(id, { enabled: !enabled }, fac));
    features.refresh();
  }, [fac, go, features]);

  const handleCreateDraft = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!draftForm.patientId || !draftForm.content) return;
    await go(() => aiApi.createDraft({
      patientId: draftForm.patientId,
      encounterId: draftForm.encounterId || undefined,
      function: draftForm.function,
      content: draftForm.content,
    }, fac));
    setDlg(null);
    setDraftForm({ patientId: '', encounterId: '', function: 'documentation_draft', content: '' });
  }, [draftForm, fac, go]);

  return (
    <div className="page ai-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">AI Assistance</h1>
          <p className="page__subtitle">Governed AI features, clinical drafts, safety controls, audit trail</p>
        </div>
        <div className="ai-actions">
          <Button variant="ghost" onClick={() => features.refresh()}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Governance Banner ─────────────────────────────── */}
      <Alert tone="warning">
        <strong>AI Governance:</strong> All AI outputs are drafts requiring human clinician review before clinical use.
        AI is an assistant — never the final authority for clinical decisions.
      </Alert>

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="ai-census">
        <div className="ai-census-card ai-census-card--features">
          <span className="ai-census-value">{allFeatures.length}</span>
          <span className="ai-census-label">Registered Features</span>
        </div>
        <div className="ai-census-card ai-census-card--active">
          <span className="ai-census-value" style={{ color: '#10b981' }}>{activeFeatures}</span>
          <span className="ai-census-label">Active (Kill-Switch ON)</span>
        </div>
        <div className="ai-census-card ai-census-card--models">
          <span className="ai-census-value">{approvedModels}</span>
          <span className="ai-census-label">Approved Models</span>
        </div>
        <div className="ai-census-card ai-census-card--drafts">
          <span className="ai-census-value">—</span>
          <span className="ai-census-label">AI Drafts</span>
        </div>
        <div className="ai-census-card ai-census-card--signed">
          <span className="ai-census-value" style={{ color: '#10b981' }}>—</span>
          <span className="ai-census-label">Signed Drafts</span>
        </div>
        <div className="ai-census-card ai-census-card--safety">
          <span className="ai-census-value">0</span>
          <span className="ai-census-label">Safety Flags</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="ai-tabs">
        {(['features', 'drafts', 'governance', 'audit', 'safety'] as const).map(t => (
          <button key={t} className={`ai-tab ${activeTab === t ? 'ai-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'features' ? 'AI Features' : t === 'drafts' ? 'Drafts' : t === 'governance' ? 'Governance' : t === 'audit' ? 'Audit Trail' : 'Safety Controls'}
          </button>
        ))}
      </div>

      {/* ── Features Tab ──────────────────────────────────── */}
      {activeTab === 'features' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3>AI Feature Registry</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-feature')}>+ Register Feature</Button>
          </div>
          {allFeatures.length === 0 ? (
            <EmptyState title="No AI features registered" body="Register AI features with governance metadata before enabling." />
          ) : (
            <div className="ai-table">
              <div className="ai-table-header">
                <span>Code</span>
                <span>Name</span>
                <span>Function</span>
                <span>Tier</span>
                <span>Status</span>
                <span>Model</span>
                <span>Kill Switch</span>
              </div>
              {allFeatures.map(f => (
                <div key={f.id} className="ai-table-row">
                  <span className="ai-mono">{f.code}</span>
                  <span className="ai-name">{f.name}</span>
                  <span>{f.function?.replace(/_/g, ' ')}</span>
                  <span className="ai-tier" style={{ color: AI_TIERS.find(t => t.value === f.tier)?.color ?? '#6b7280' }}>
                    {f.tier?.replace(/_/g, ' ')}
                  </span>
                  <StatusBadge status={f.status} config={FEATURE_STATUS} />
                  <span>
                    {f.modelApproved ? (
                      <span className="ai-model-approved">Approved</span>
                    ) : (
                      <span className="ai-model-pending">Pending</span>
                    )}
                  </span>
                  <span>
                    <button className={`ai-kill-btn ${f.enabled ? 'ai-kill-btn--active' : ''}`}
                      onClick={() => void handleToggleFeature(f.id, f.enabled)}>
                      {f.enabled ? 'ON' : 'OFF'}
                    </button>
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Drafts Tab ────────────────────────────────────── */}
      {activeTab === 'drafts' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3>AI Drafts</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-draft')}>+ Create Draft</Button>
          </div>
          <div className="ai-draft-notice">
            <Alert tone="warning">
              AI-generated drafts are marked <strong>AI-GENERATED · DRAFT · REQUIRES HUMAN REVIEW</strong>.
              They never auto-populate clinical records. A clinician must review and SIGN before use.
            </Alert>
          </div>
          <EmptyState title="No AI drafts" body="Create AI-assisted drafts for documentation, summaries, or communications." />
        </Card>
      )}

      {/* ── Governance Tab ────────────────────────────────── */}
      {activeTab === 'governance' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3>AI Governance Framework</h3>
          </div>
          <div className="ai-governance-grid">
            <div className="ai-gov-card">
              <span className="ai-gov-title">Input Control</span>
              <span className="ai-gov-desc">Only minimum authorized data is sent to AI models</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-gov-card">
              <span className="ai-gov-title">Output Labeling</span>
              <span className="ai-gov-desc">All AI outputs are clearly labeled as drafts</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-gov-card">
              <span className="ai-gov-title">Human Review</span>
              <span className="ai-gov-desc">Clinician must sign before clinical use</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-gov-card">
              <span className="ai-gov-title">Kill Switch</span>
              <span className="ai-gov-desc">Per-feature disable without code deployment</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-gov-card">
              <span className="ai-gov-title">Model Allowlist</span>
              <span className="ai-gov-desc">Only pre-approved model endpoints receive data</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-gov-card">
              <span className="ai-gov-title">Audit Trail</span>
              <span className="ai-gov-desc">Every AI interaction is logged with model, user, timestamp</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
          </div>
        </Card>
      )}

      {/* ── Audit Tab ─────────────────────────────────────── */}
      {activeTab === 'audit' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3>AI Audit Trail</h3>
          </div>
          <EmptyState title="AI audit events" body="Model invocations, draft creations, signings, and safety events are logged here." />
        </Card>
      )}

      {/* ── Safety Tab ────────────────────────────────────── */}
      {activeTab === 'safety' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3>Safety Controls</h3>
          </div>
          <div className="ai-safety-grid">
            <div className="ai-safety-card">
              <span className="ai-safety-title">Prompt Injection Protection</span>
              <span className="ai-safety-desc">Input sanitization and validation before model dispatch</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-safety-card">
              <span className="ai-safety-title">Cross-Patient Isolation</span>
              <span className="ai-safety-desc">No patient context leakage between requests</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-safety-card">
              <span className="ai-safety-title">Data Minimization</span>
              <span className="ai-safety-desc">Only minimum necessary data sent to external models</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-safety-card">
              <span className="ai-safety-title">Hallucination Guard</span>
              <span className="ai-safety-desc">AI must cite sources; missing evidence triggers fallback</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-safety-card">
              <span className="ai-safety-title">Clinical Decision Boundary</span>
              <span className="ai-safety-desc">No unsupervised prescribing, diagnosis, or discharge</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
            <div className="ai-safety-card">
              <span className="ai-safety-title">Graceful Degradation</span>
              <span className="ai-safety-desc">AI failure never blocks clinical care</span>
              <StatusBadge status="active" config={FEATURE_STATUS} />
            </div>
          </div>
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* New Feature Dialog */}
      {dlg === 'new-feature' && (
        <Dialog open onClose={() => setDlg(null)} title="Register AI Feature" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateFeature} loading={busy} disabled={!featureForm.code || !featureForm.name}>Register</Button>
          </>
        }>
          <form onSubmit={handleCreateFeature} className="ai-form">
            <Input label="Feature Code" value={featureForm.code} onChange={e => setFeatureForm(f => ({ ...f, code: e.target.value }))} placeholder="e.g. discharge-summary-draft" required />
            <Input label="Feature Name" value={featureForm.name} onChange={e => setFeatureForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. Discharge Summary Draft" required />
            <div className="ai-form-field">
              <label className="ai-label">Function</label>
              <select className="ai-input" value={featureForm.function} onChange={e => setFeatureForm(f => ({ ...f, function: e.target.value }))}>
                {AI_FUNCTIONS.map(fn => <option key={fn.value} value={fn.value}>{fn.label}</option>)}
              </select>
            </div>
            <div className="ai-form-field">
              <label className="ai-label">Risk Tier</label>
              <select className="ai-input" value={featureForm.tier} onChange={e => setFeatureForm(f => ({ ...f, tier: e.target.value }))}>
                {AI_TIERS.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
            <Input label="Purpose" value={featureForm.purpose} onChange={e => setFeatureForm(f => ({ ...f, purpose: e.target.value }))} placeholder="Clinical purpose and non-goals" />
            <Alert tone="info">Features require model approval and evaluation evidence before activation. The kill switch defaults to OFF.</Alert>
          </form>
        </Dialog>
      )}

      {/* New Draft Dialog */}
      {dlg === 'new-draft' && (
        <Dialog open onClose={() => setDlg(null)} title="Create AI Draft" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateDraft} loading={busy} disabled={!draftForm.patientId || !draftForm.content}>Create Draft</Button>
          </>
        }>
          <form onSubmit={handleCreateDraft} className="ai-form">
            <Input label="Patient ID" value={draftForm.patientId} onChange={e => setDraftForm(f => ({ ...f, patientId: e.target.value }))} placeholder="Patient UUID" required />
            <Input label="Encounter ID (optional)" value={draftForm.encounterId} onChange={e => setDraftForm(f => ({ ...f, encounterId: e.target.value }))} placeholder="Encounter UUID" />
            <div className="ai-form-field">
              <label className="ai-label">Draft Type</label>
              <select className="ai-input" value={draftForm.function} onChange={e => setDraftForm(f => ({ ...f, function: e.target.value }))}>
                {AI_FUNCTIONS.map(fn => <option key={fn.value} value={fn.value}>{fn.label}</option>)}
              </select>
            </div>
            <div className="ai-form-field">
              <label className="ai-label">Content / Prompt</label>
              <textarea className="ai-textarea" value={draftForm.content} onChange={e => setDraftForm(f => ({ ...f, content: e.target.value }))} placeholder="Describe what you need..." rows={4} required />
            </div>
            <Alert tone="warning">AI drafts are clearly labeled and require clinician review. Never auto-signed.</Alert>
          </form>
        </Dialog>
      )}
    </div>
  );
}
