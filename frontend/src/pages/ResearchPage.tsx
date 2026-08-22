import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { api } from '../api/client';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/research.css';

/* ── API Client ──────────────────────────────────────────────────── */

const opt = (facilityId?: string | null) => ({ facilityId } as Record<string, unknown>);

const researchApi = {
  projects: (fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/analytics/research/projects`, opt(fac)).catch(() => []),
  storeProject: (payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>('/api/v1/analytics/research/projects', { method: 'POST', body: payload, ...opt(fac) }),
  cohorts: (fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/analytics/research/cohorts`, opt(fac)).catch(() => []),
  storeCohort: (payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>('/api/v1/analytics/research/cohorts', { method: 'POST', body: payload, ...opt(fac) }),
};

/* ── Types ───────────────────────────────────────────────────────── */

interface ResearchProject {
  id: string;
  title: string;
  principalInvestigator: string;
  institution: string;
  status: string;
  startDate: string | null;
  endDate: string | null;
  approvalStatus: string;
  description: string | null;
}

interface ResearchCohort {
  id: string;
  name: string;
  criteria: string;
  size: number | null;
  status: string;
  createdAt: string;
}

/* ── Constants ───────────────────────────────────────────────────── */

const PROJECT_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  draft: { label: 'Draft', color: '#6b7280', bg: '#f3f4f6' },
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  completed: { label: 'Completed', color: '#3b82f6', bg: '#dbeafe' },
  archived: { label: 'Archived', color: '#9ca3af', bg: '#f9fafb' },
};

const APPROVAL_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  pending: { label: 'Pending', color: '#f59e0b', bg: '#fef3c7' },
  approved: { label: 'Approved', color: '#10b981', bg: '#ecfdf5' },
  rejected: { label: 'Rejected', color: '#ef4444', bg: '#fee2e2' },
  expired: { label: 'Expired', color: '#6b7280', bg: '#f3f4f6' },
};

const COHORT_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  draft: { label: 'Draft', color: '#6b7280', bg: '#f3f4f6' },
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  archived: { label: 'Archived', color: '#9ca3af', bg: '#f9fafb' },
};

function StatusBadge({ status, config }: { status: string; config: Record<string, { label: string; color: string; bg: string }> }) {
  const c = config[status] ?? { label: status.replace(/_/g, ' '), color: '#6b7280', bg: '#f3f4f6' };
  return <span className="res-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function ResearchPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'projects' | 'data-access' | 'cohorts' | 'deidentification' | 'population'>('projects');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Forms
  const [projectForm, setProjectForm] = useState({ title: '', principalInvestigator: '', institution: '', description: '' });
  const [cohortForm, setCohortForm] = useState({ name: '', criteria: '' });

  // Data fetching
  const projects = useFetch(
    () => researchApi.projects(fac),
    [fac],
  );

  const cohorts = useFetch(
    () => researchApi.cohorts(fac),
    [fac],
  );

  const allProjects = useMemo(() => (projects.data ?? []) as unknown as ResearchProject[], [projects.data]);
  const allCohorts = useMemo(() => (cohorts.data ?? []) as unknown as ResearchCohort[], [cohorts.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Census
  const activeProjects = allProjects.filter(p => p.status === 'active').length;
  const pendingApprovals = allProjects.filter(p => p.approvalStatus === 'pending').length;
  const activeCohorts = allCohorts.filter(c => c.status === 'active').length;

  const handleCreateProject = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!projectForm.title) return;
    await go(() => researchApi.storeProject({
      title: projectForm.title,
      principalInvestigator: projectForm.principalInvestigator,
      institution: projectForm.institution,
      description: projectForm.description || undefined,
    }, fac));
    setDlg(null);
    setProjectForm({ title: '', principalInvestigator: '', institution: '', description: '' });
    projects.refresh();
  }, [projectForm, fac, go, projects]);

  const handleCreateCohort = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!cohortForm.name || !cohortForm.criteria) return;
    await go(() => researchApi.storeCohort({
      name: cohortForm.name,
      criteria: cohortForm.criteria,
    }, fac));
    setDlg(null);
    setCohortForm({ name: '', criteria: '' });
    cohorts.refresh();
  }, [cohortForm, fac, go, cohorts]);

  return (
    <div className="page res-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Research & Population Health</h1>
          <p className="page__subtitle">Research projects, data access, cohorts, de-identification, population analytics</p>
        </div>
        <div className="res-actions">
          <Button variant="ghost" onClick={() => { projects.refresh(); cohorts.refresh(); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="res-census">
        <div className="res-census-card res-census-card--projects">
          <span className="res-census-value">{allProjects.length}</span>
          <span className="res-census-label">Research Projects</span>
        </div>
        <div className="res-census-card res-census-card--active">
          <span className="res-census-value" style={{ color: '#10b981' }}>{activeProjects}</span>
          <span className="res-census-label">Active Projects</span>
        </div>
        <div className="res-census-card res-census-card--pending">
          <span className="res-census-value" style={{ color: '#f59e0b' }}>{pendingApprovals}</span>
          <span className="res-census-label">Pending Approvals</span>
        </div>
        <div className="res-census-card res-census-card--cohorts">
          <span className="res-census-value">{allCohorts.length}</span>
          <span className="res-census-label">Cohorts</span>
        </div>
        <div className="res-census-card res-census-card--active-cohorts">
          <span className="res-census-value" style={{ color: '#3b82f6' }}>{activeCohorts}</span>
          <span className="res-census-label">Active Cohorts</span>
        </div>
        <div className="res-census-card res-census-card--deidentified">
          <span className="res-census-value">—</span>
          <span className="res-census-label">De-identified Datasets</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="res-tabs">
        {(['projects', 'data-access', 'cohorts', 'deidentification', 'population'] as const).map(t => (
          <button key={t} className={`res-tab ${activeTab === t ? 'res-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'projects' ? 'Projects' : t === 'data-access' ? 'Data Access' : t === 'cohorts' ? 'Cohorts' : t === 'deidentification' ? 'De-identification' : 'Population Health'}
          </button>
        ))}
      </div>

      {/* ── Projects Tab ──────────────────────────────────── */}
      {activeTab === 'projects' && (
        <Card className="res-section-card">
          <div className="res-section-header">
            <h3>Research Projects</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-project')}>+ New Project</Button>
          </div>
          {allProjects.length === 0 ? (
            <EmptyState title="No research projects" body="Create a research project to begin. All projects require IRB approval before data access." />
          ) : (
            <div className="res-table">
              <div className="res-table-header">
                <span>Title</span>
                <span>PI</span>
                <span>Institution</span>
                <span>Status</span>
                <span>Approval</span>
                <span>Start</span>
              </div>
              {allProjects.map(p => (
                <div key={p.id} className="res-table-row">
                  <span className="res-name">{p.title}</span>
                  <span>{p.principalInvestigator}</span>
                  <span>{p.institution}</span>
                  <StatusBadge status={p.status} config={PROJECT_STATUS} />
                  <StatusBadge status={p.approvalStatus} config={APPROVAL_STATUS} />
                  <span>{p.startDate ? new Date(p.startDate).toLocaleDateString() : '—'}</span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Data Access Tab ───────────────────────────────── */}
      {activeTab === 'data-access' && (
        <Card className="res-section-card">
          <div className="res-section-header">
            <h3>Data Access Requests</h3>
          </div>
          <div className="res-access-info">
            <Alert tone="info">
              All data access requests require IRB approval and are reviewed by the data governance committee.
              No unrestricted database exports are permitted. De-identified datasets only.
            </Alert>
          </div>
          <EmptyState title="No data access requests" body="Request access to research datasets through approved channels." />
        </Card>
      )}

      {/* ── Cohorts Tab ───────────────────────────────────── */}
      {activeTab === 'cohorts' && (
        <Card className="res-section-card">
          <div className="res-section-header">
            <h3>Research Cohorts</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-cohort')}>+ New Cohort</Button>
          </div>
          {allCohorts.length === 0 ? (
            <EmptyState title="No cohorts" body="Define research cohorts using authorized clinical criteria." />
          ) : (
            <div className="res-table">
              <div className="res-table-header">
                <span>Name</span>
                <span>Criteria</span>
                <span>Size</span>
                <span>Status</span>
                <span>Created</span>
              </div>
              {allCohorts.map(c => (
                <div key={c.id} className="res-table-row">
                  <span className="res-name">{c.name}</span>
                  <span className="res-criteria">{c.criteria}</span>
                  <span>{c.size ?? '—'}</span>
                  <StatusBadge status={c.status} config={COHORT_STATUS} />
                  <span>{c.createdAt ? new Date(c.createdAt).toLocaleDateString() : '—'}</span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── De-identification Tab ─────────────────────────── */}
      {activeTab === 'deidentification' && (
        <Card className="res-section-card">
          <div className="res-section-header">
            <h3>De-identification</h3>
          </div>
          <div className="res-deid-info">
            <Alert tone="warning">
              De-identification is performed through controlled workflows. PHI is never exported in research datasets.
              All de-identification actions are audited and logged.
            </Alert>
          </div>
          <div className="res-deid-methods">
            <div className="res-deid-card">
              <span className="res-deid-name">Pseudonymization</span>
              <span className="res-deid-desc">Replace direct identifiers with tokens</span>
              <StatusBadge status="active" config={PROJECT_STATUS} />
            </div>
            <div className="res-deid-card">
              <span className="res-deid-name">Generalization</span>
              <span className="res-deid-desc">Reduce precision of quasi-identifiers</span>
              <StatusBadge status="active" config={PROJECT_STATUS} />
            </div>
            <div className="res-deid-card">
              <span className="res-deid-name">Suppression</span>
              <span className="res-deid-desc">Remove rare values to prevent re-identification</span>
              <StatusBadge status="active" config={PROJECT_STATUS} />
            </div>
            <div className="res-deid-card">
              <span className="res-deid-name">K-Anonymity Check</span>
              <span className="res-deid-desc">Verify minimum group size requirements</span>
              <StatusBadge status="draft" config={PROJECT_STATUS} />
            </div>
          </div>
        </Card>
      )}

      {/* ── Population Health Tab ─────────────────────────── */}
      {activeTab === 'population' && (
        <Card className="res-section-card">
          <div className="res-section-header">
            <h3>Population Health Analytics</h3>
          </div>
          <div className="res-pop-grid">
            <div className="res-pop-card">
              <span className="res-pop-label">Disease Prevalence</span>
              <span className="res-pop-value">—</span>
              <span className="res-pop-sub">Top diagnoses by frequency</span>
            </div>
            <div className="res-pop-card">
              <span className="res-pop-label">Service Utilization</span>
              <span className="res-pop-value">—</span>
              <span className="res-pop-sub">OPD, IPD, ED visit patterns</span>
            </div>
            <div className="res-pop-card">
              <span className="res-pop-label">Geographic Distribution</span>
              <span className="res-pop-value">—</span>
              <span className="res-pop-sub">Patient origin analysis</span>
            </div>
            <div className="res-pop-card">
              <span className="res-pop-label">Trend Analysis</span>
              <span className="res-pop-value">—</span>
              <span className="res-pop-sub">Monthly/quarterly trends</span>
            </div>
          </div>
          <EmptyState title="Population health" body="Aggregate analytics are generated from de-identified data. Individual patient records are never exposed." />
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* New Project Dialog */}
      {dlg === 'new-project' && (
        <Dialog open onClose={() => setDlg(null)} title="New Research Project" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateProject} loading={busy} disabled={!projectForm.title}>Create Project</Button>
          </>
        }>
          <form onSubmit={handleCreateProject} className="res-form">
            <Input label="Project Title" value={projectForm.title} onChange={e => setProjectForm(f => ({ ...f, title: e.target.value }))} placeholder="e.g. Cardiovascular Outcomes Study" required />
            <Input label="Principal Investigator" value={projectForm.principalInvestigator} onChange={e => setProjectForm(f => ({ ...f, principalInvestigator: e.target.value }))} placeholder="e.g. Dr. Smith" />
            <Input label="Institution" value={projectForm.institution} onChange={e => setProjectForm(f => ({ ...f, institution: e.target.value }))} placeholder="e.g. Teaching Hospital" />
            <Input label="Description" value={projectForm.description} onChange={e => setProjectForm(f => ({ ...f, description: e.target.value }))} placeholder="Brief project description" />
            <Alert tone="info">Research projects require IRB approval before any patient data access is granted.</Alert>
          </form>
        </Dialog>
      )}

      {/* New Cohort Dialog */}
      {dlg === 'new-cohort' && (
        <Dialog open onClose={() => setDlg(null)} title="Define Research Cohort" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateCohort} loading={busy} disabled={!cohortForm.name || !cohortForm.criteria}>Create Cohort</Button>
          </>
        }>
          <form onSubmit={handleCreateCohort} className="res-form">
            <Input label="Cohort Name" value={cohortForm.name} onChange={e => setCohortForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. Type 2 Diabetes Patients" required />
            <div className="res-form-field">
              <label className="res-label">Criteria</label>
              <textarea className="res-textarea" value={cohortForm.criteria} onChange={e => setCohortForm(f => ({ ...f, criteria: e.target.value }))} placeholder="Define inclusion/exclusion criteria..." rows={3} required />
            </div>
            <Alert tone="warning">Cohort queries use only authorized, de-identified data. Patient identifiers are never exposed in research contexts.</Alert>
          </form>
        </Dialog>
      )}
    </div>
  );
}
