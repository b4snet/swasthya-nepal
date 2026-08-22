import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { api } from '../api/client';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/quality.css';

/* ── API Client ──────────────────────────────────────────────────── */

const opt = (facilityId?: string | null) => ({ facilityId } as Record<string, unknown>);

const qualityApi = {
  complianceReports: (fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/analytics/compliance-reports`, opt(fac)),
  storeComplianceReport: (payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>('/api/v1/analytics/compliance-reports', { method: 'POST', body: payload, ...opt(fac) }),
  showComplianceReport: (id: string, fac?: string | null) =>
    api.request<unknown>(`/api/v1/compliance-reports/${id}`, opt(fac)),
  storeItem: (reportId: string, payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>(`/api/v1/compliance-reports/${reportId}/items`, { method: 'POST', body: payload, ...opt(fac) }),
  publish: (reportId: string, fac?: string | null) =>
    api.request<unknown>(`/api/v1/compliance-reports/${reportId}/publish`, { method: 'POST', body: {}, ...opt(fac) }),
  acknowledge: (reportId: string, fac?: string | null) =>
    api.request<unknown>(`/api/v1/compliance-reports/${reportId}/acknowledge`, { method: 'POST', body: {}, ...opt(fac) }),
};

/* ── Types ───────────────────────────────────────────────────────── */

interface ComplianceReport {
  id: string;
  reportCode: string;
  title: string;
  category: string;
  scope: string;
  status: string;
  summary: string | null;
  generatedAt: string | null;
  publishedAt: string | null;
  acknowledgedAt: string | null;
  version: number;
}

/* ── Constants ───────────────────────────────────────────────────── */

const REPORT_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  draft: { label: 'Draft', color: '#6b7280', bg: '#f3f4f6' },
  published: { label: 'Published', color: '#3b82f6', bg: '#dbeafe' },
  acknowledged: { label: 'Acknowledged', color: '#10b981', bg: '#ecfdf5' },
  archived: { label: 'Archived', color: '#9ca3af', bg: '#f9fafb' },
};

const CATEGORY_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  privacy: { label: 'Privacy', color: '#8b5cf6', bg: '#f5f3ff' },
  security: { label: 'Security', color: '#ef4444', bg: '#fee2e2' },
  clinical_quality: { label: 'Clinical Quality', color: '#10b981', bg: '#ecfdf5' },
  financial_controls: { label: 'Financial Controls', color: '#f59e0b', bg: '#fef3c7' },
  operational_governance: { label: 'Operational Governance', color: '#3b82f6', bg: '#dbeafe' },
};

const SEVERITY_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  low: { label: 'Low', color: '#10b981', bg: '#ecfdf5' },
  moderate: { label: 'Moderate', color: '#f59e0b', bg: '#fef3c7' },
  high: { label: 'High', color: '#ef4444', bg: '#fee2e2' },
  critical: { label: 'Critical', color: '#dc2626', bg: '#fef2f2' },
};

const SAFETY_EVENT_TYPES = [
  'Medication Error',
  'Patient Fall',
  'Patient Identification',
  'Transfusion Event',
  'Infection Event',
  'Procedure Incident',
  'Equipment Failure',
  'Documentation Error',
  'Communication Failure',
  'Other',
];

function StatusBadge({ status, config }: { status: string; config: Record<string, { label: string; color: string; bg: string }> }) {
  const c = config[status] ?? { label: status.replace(/_/g, ' '), color: '#6b7280', bg: '#f3f4f6' };
  return <span className="q-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function QualityPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'dashboard' | 'incidents' | 'safety' | 'capa' | 'compliance' | 'infection'>('dashboard');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Forms
  const [reportForm, setReportForm] = useState({ title: '', category: 'clinical_quality', scope: 'facility', summary: '' });
  const [safetyForm, setSafetyForm] = useState({ type: 'Medication Error', severity: 'moderate', department: '', description: '' });
  const [capaForm, setCapaForm] = useState({ action: '', owner: '', deadline: '' });

  // Data fetching
  const reports = useFetch(
    () => qualityApi.complianceReports(fac).catch(() => []),
    [fac],
  );

  const allReports = useMemo(() => (reports.data ?? []) as unknown as ComplianceReport[], [reports.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Census
  const draftReports = allReports.filter(r => r.status === 'draft').length;
  const publishedReports = allReports.filter(r => r.status === 'published').length;
  const acknowledgedReports = allReports.filter(r => r.status === 'acknowledged').length;

  const handleCreateReport = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!reportForm.title) return;
    await go(() => qualityApi.storeComplianceReport({
      title: reportForm.title,
      category: reportForm.category,
      scope: reportForm.scope,
      summary: reportForm.summary || undefined,
    }, fac));
    setDlg(null);
    setReportForm({ title: '', category: 'clinical_quality', scope: 'facility', summary: '' });
    reports.refresh();
  }, [reportForm, fac, go, reports]);

  const handlePublish = useCallback(async (id: string) => {
    await go(() => qualityApi.publish(id, fac));
    reports.refresh();
  }, [fac, go, reports]);

  const handleAcknowledge = useCallback(async (id: string) => {
    await go(() => qualityApi.acknowledge(id, fac));
    reports.refresh();
  }, [fac, go, reports]);

  return (
    <div className="page q-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Quality & Patient Safety</h1>
          <p className="page__subtitle">Incidents, safety events, CAPAs, compliance, infection control</p>
        </div>
        <div className="q-actions">
          <Button variant="ghost" onClick={() => reports.refresh()}>Refresh</Button>
          <Button variant="primary" size="sm" onClick={() => setDlg('new-report')}>+ New Report</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="q-census">
        <div className="q-census-card q-census-card--total">
          <span className="q-census-value">{allReports.length}</span>
          <span className="q-census-label">Compliance Reports</span>
        </div>
        <div className="q-census-card q-census-card--draft">
          <span className="q-census-value">{draftReports}</span>
          <span className="q-census-label">Draft</span>
        </div>
        <div className="q-census-card q-census-card--published">
          <span className="q-census-value" style={{ color: '#3b82f6' }}>{publishedReports}</span>
          <span className="q-census-label">Published</span>
        </div>
        <div className="q-census-card q-census-card--acknowledged">
          <span className="q-census-value" style={{ color: '#10b981' }}>{acknowledgedReports}</span>
          <span className="q-census-label">Acknowledged</span>
        </div>
        <div className="q-census-card q-census-card--safety">
          <span className="q-census-value">—</span>
          <span className="q-census-label">Safety Events</span>
        </div>
        <div className="q-census-card q-census-card--capa">
          <span className="q-census-value">—</span>
          <span className="q-census-label">Open CAPAs</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="q-tabs">
        {(['dashboard', 'incidents', 'safety', 'capa', 'compliance', 'infection'] as const).map(t => (
          <button key={t} className={`q-tab ${activeTab === t ? 'q-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'dashboard' ? 'Dashboard' : t === 'incidents' ? 'Incidents' : t === 'safety' ? 'Safety Events' : t === 'capa' ? 'CAPAs' : t === 'compliance' ? 'Compliance' : 'Infection Control'}
          </button>
        ))}
      </div>

      {/* ── Dashboard Tab ─────────────────────────────────── */}
      {activeTab === 'dashboard' && (
        <div className="q-dashboard">
          <Card className="q-section-card">
            <div className="q-section-header">
              <h3>Quality Overview</h3>
            </div>
            <div className="q-overview-grid">
              <div className="q-overview-card">
                <span className="q-overview-label">Incidents This Month</span>
                <span className="q-overview-value">—</span>
              </div>
              <div className="q-overview-card">
                <span className="q-overview-label">Open CAPAs</span>
                <span className="q-overview-value q-overview-value--warning">—</span>
              </div>
              <div className="q-overview-card">
                <span className="q-overview-label">Overdue Actions</span>
                <span className="q-overview-value q-overview-value--danger">—</span>
              </div>
              <div className="q-overview-card">
                <span className="q-overview-label">Compliance Score</span>
                <span className="q-overview-value q-overview-value--success">—</span>
              </div>
            </div>
          </Card>

          <Card className="q-section-card">
            <div className="q-section-header">
              <h3>Recent Compliance Reports</h3>
            </div>
            {allReports.length === 0 ? (
              <EmptyState title="No reports" body="Create compliance reports to track quality metrics." />
            ) : (
              <div className="q-report-list">
                {allReports.slice(0, 5).map(r => (
                  <div key={r.id} className="q-report-item">
                    <div className="q-report-header">
                      <span className="q-report-title">{r.title}</span>
                      <StatusBadge status={r.status} config={REPORT_STATUS} />
                    </div>
                    <div className="q-report-meta">
                      <StatusBadge status={r.category} config={CATEGORY_CONFIG} />
                      <span className="q-report-code">{r.reportCode}</span>
                      <span>{r.generatedAt ? new Date(r.generatedAt).toLocaleDateString() : '—'}</span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>
        </div>
      )}

      {/* ── Incidents Tab ─────────────────────────────────── */}
      {activeTab === 'incidents' && (
        <Card className="q-section-card">
          <div className="q-section-header">
            <h3>Incident Management</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-safety')}>+ Report Incident</Button>
          </div>
          <EmptyState title="Incident reports" body="Report and track safety incidents, near-misses, and quality events." />
        </Card>
      )}

      {/* ── Safety Events Tab ─────────────────────────────── */}
      {activeTab === 'safety' && (
        <Card className="q-section-card">
          <div className="q-section-header">
            <h3>Patient Safety Events</h3>
          </div>
          <div className="q-safety-types">
            {SAFETY_EVENT_TYPES.map(type => (
              <div key={type} className="q-safety-type-card">
                <span className="q-safety-type-name">{type}</span>
                <span className="q-safety-type-count">— events</span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* ── CAPA Tab ──────────────────────────────────────── */}
      {activeTab === 'capa' && (
        <Card className="q-section-card">
          <div className="q-section-header">
            <h3>Corrective & Preventive Actions</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-capa')}>+ New CAPA</Button>
          </div>
          <EmptyState title="No open CAPAs" body="Track corrective and preventive actions from incident investigations." />
        </Card>
      )}

      {/* ── Compliance Tab ────────────────────────────────── */}
      {activeTab === 'compliance' && (
        <Card className="q-section-card">
          <div className="q-section-header">
            <h3>Compliance Reports</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-report')}>+ New Report</Button>
          </div>
          {allReports.length === 0 ? (
            <EmptyState title="No compliance reports" body="Create compliance reports to track regulatory requirements." />
          ) : (
            <div className="q-table">
              <div className="q-table-header">
                <span>Code</span>
                <span>Title</span>
                <span>Category</span>
                <span>Status</span>
                <span>Version</span>
                <span>Actions</span>
              </div>
              {allReports.map(r => (
                <div key={r.id} className="q-table-row">
                  <span className="q-mono">{r.reportCode}</span>
                  <span className="q-name">{r.title}</span>
                  <StatusBadge status={r.category} config={CATEGORY_CONFIG} />
                  <StatusBadge status={r.status} config={REPORT_STATUS} />
                  <span>v{r.version}</span>
                  <span className="q-table-actions">
                    {r.status === 'draft' && (
                      <Button variant="ghost" size="sm" onClick={() => void handlePublish(r.id)} loading={busy}>Publish</Button>
                    )}
                    {r.status === 'published' && (
                      <Button variant="ghost" size="sm" onClick={() => void handleAcknowledge(r.id)} loading={busy}>Acknowledge</Button>
                    )}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Infection Control Tab ─────────────────────────── */}
      {activeTab === 'infection' && (
        <Card className="q-section-card">
          <div className="q-section-header">
            <h3>Infection Control</h3>
          </div>
          <div className="q-infection-grid">
            <div className="q-infection-card">
              <span className="q-infection-label">Healthcare-Associated Infections</span>
              <span className="q-infection-value">—</span>
            </div>
            <div className="q-infection-card">
              <span className="q-infection-label">Isolation Patients</span>
              <span className="q-infection-value">—</span>
            </div>
            <div className="q-infection-card">
              <span className="q-infection-label">Hand Hygiene Compliance</span>
              <span className="q-infection-value">—</span>
            </div>
            <div className="q-infection-card">
              <span className="q-infection-label">Antimicrobial Stewardship</span>
              <span className="q-infection-value">—</span>
            </div>
          </div>
          <EmptyState title="Infection control surveillance" body="Track healthcare-associated infections, isolation status, and antimicrobial stewardship." />
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* New Report Dialog */}
      {dlg === 'new-report' && (
        <Dialog open onClose={() => setDlg(null)} title="New Compliance Report" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateReport} loading={busy} disabled={!reportForm.title}>Create Report</Button>
          </>
        }>
          <form onSubmit={handleCreateReport} className="q-form">
            <Input label="Title" value={reportForm.title} onChange={e => setReportForm(f => ({ ...f, title: e.target.value }))} placeholder="e.g. Q3 2026 Compliance Review" required />
            <div className="q-form-field">
              <label className="q-label">Category</label>
              <select className="q-input" value={reportForm.category} onChange={e => setReportForm(f => ({ ...f, category: e.target.value }))}>
                {Object.entries(CATEGORY_CONFIG).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
              </select>
            </div>
            <div className="q-form-field">
              <label className="q-label">Scope</label>
              <select className="q-input" value={reportForm.scope} onChange={e => setReportForm(f => ({ ...f, scope: e.target.value }))}>
                <option value="facility">Facility</option>
                <option value="department">Department</option>
                <option value="organization">Organization</option>
              </select>
            </div>
            <Input label="Summary" value={reportForm.summary} onChange={e => setReportForm(f => ({ ...f, summary: e.target.value }))} placeholder="Executive summary..." />
          </form>
        </Dialog>
      )}

      {/* New Safety Event Dialog */}
      {dlg === 'new-safety' && (
        <Dialog open onClose={() => setDlg(null)} title="Report Safety Event" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => { setDlg(null); }} loading={busy}>Submit Report</Button>
          </>
        }>
          <form className="q-form">
            <div className="q-form-field">
              <label className="q-label">Event Type</label>
              <select className="q-input" value={safetyForm.type} onChange={e => setSafetyForm(f => ({ ...f, type: e.target.value }))}>
                {SAFETY_EVENT_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <div className="q-form-field">
              <label className="q-label">Severity</label>
              <select className="q-input" value={safetyForm.severity} onChange={e => setSafetyForm(f => ({ ...f, severity: e.target.value }))}>
                {Object.entries(SEVERITY_CONFIG).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
              </select>
            </div>
            <Input label="Department" value={safetyForm.department} onChange={e => setSafetyForm(f => ({ ...f, department: e.target.value }))} placeholder="e.g. Emergency" />
            <Input label="Description" value={safetyForm.description} onChange={e => setSafetyForm(f => ({ ...f, description: e.target.value }))} placeholder="Describe the event..." />
            <Alert tone="warning">Safety event reports are confidential and access-restricted. Only quality staff can view full event details.</Alert>
          </form>
        </Dialog>
      )}

      {/* New CAPA Dialog */}
      {dlg === 'new-capa' && (
        <Dialog open onClose={() => setDlg(null)} title="New CAPA" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => { setDlg(null); }} loading={busy}>Create CAPA</Button>
          </>
        }>
          <form className="q-form">
            <Input label="Corrective Action" value={capaForm.action} onChange={e => setCapaForm(f => ({ ...f, action: e.target.value }))} placeholder="Describe the corrective action..." />
            <Input label="Owner" value={capaForm.owner} onChange={e => setCapaForm(f => ({ ...f, owner: e.target.value }))} placeholder="Responsible person" />
            <Input label="Deadline" type="date" value={capaForm.deadline} onChange={e => setCapaForm(f => ({ ...f, deadline: e.target.value }))} />
          </form>
        </Dialog>
      )}
    </div>
  );
}
