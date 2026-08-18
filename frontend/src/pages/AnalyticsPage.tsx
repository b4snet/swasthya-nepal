import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { analyticsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, Spinner } from '../components/ui';
import { ApiError } from '../api/client';

export function AnalyticsPage() {
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  const [tab, setTab] = useState<'kpis' | 'dashboards' | 'templates' | 'runs'>('kpis');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const canManage = hasRole('hospital_admin', 'analytics_admin');

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Analytics & Reporting</h1>
          <span className="page__sub">KPI definitions, dashboards, and report management</span>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <div className="tabs" role="tablist">
        {(['kpis', 'dashboards', 'templates', 'runs'] as const).map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} className={`tabs__tab ${tab === t ? 'tabs__tab--active' : ''}`} onClick={() => setTab(t)}>
            {t === 'kpis' ? 'KPI Definitions' : t === 'dashboards' ? 'Dashboards' : t === 'templates' ? 'Report Templates' : 'Report Runs'}
          </button>
        ))}
      </div>

      {tab === 'kpis' && <KpiTab fac={fac} canManage={canManage} setNotice={setNotice} />}
      {tab === 'dashboards' && <DashboardTab fac={fac} />}
      {tab === 'templates' && <TemplateTab fac={fac} canManage={canManage} setNotice={setNotice} />}
      {tab === 'runs' && <RunsTab fac={fac} canManage={canManage} setNotice={setNotice} />}
    </div>
  );
}

function KpiTab({ fac, canManage, setNotice }: { fac: string | null; canManage: boolean; setNotice: (n: any) => void }) {
  const [createOpen, setCreateOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const kpis = useFetch(() => analyticsApi.kpiDefinitions(fac), [fac]);

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [domain, setDomain] = useState('operational');
  const [sourceTable, setSourceTable] = useState('');
  const [dateColumn, setDateColumn] = useState('created_at');
  const [aggregation, setAggregation] = useState('count');

  const handleCreate = async () => {
    setBusy(true);
    try {
      await analyticsApi.storeKpiDefinition({ code, name, domain, sourceTable, dateColumn, aggregation }, fac);
      setNotice({ tone: 'success', text: 'KPI definition created.' });
      setCreateOpen(false);
      setCode('');
      setName('');
      setSourceTable('');
      void kpis.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed.' });
    } finally {
      setBusy(false);
    }
  };

  if (kpis.loading) return <Spinner />;
  if (kpis.error) return <ErrorState error={kpis.error} onRetry={() => void kpis.refresh()} />;

  const data = kpis.data ?? [];

  return (
    <Card title="KPI Definitions">
      {canManage && (
        <div className="row mb-4"><Button onClick={() => setCreateOpen(true)}>Add KPI</Button></div>
      )}
      {data.length === 0 ? (
        <EmptyState title="No KPI definitions" body="Define KPIs to start measuring operational metrics." />
      ) : (
        <table className="data-table">
          <thead><tr><th>Code</th><th>Name</th><th>Domain</th><th>Aggregation</th><th>Status</th><th>v{data[0]?.version}</th></tr></thead>
          <tbody>
            {data.map((k) => (
              <tr key={k.id}>
                <td className="mono">{k.code}</td>
                <td>{k.name}</td>
                <td>{k.domain}</td>
                <td>{k.aggregation}</td>
                <td><span className={`status-chip status-chip--${k.status === 'active' ? 'success' : 'neutral'}`}>{k.status}</span></td>
                <td className="num">v{k.version}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} title="Add KPI definition"
        footer={<><Button variant="ghost" onClick={() => setCreateOpen(false)}>Cancel</Button><Button onClick={() => void handleCreate()} loading={busy} disabled={!code.trim() || !name.trim() || !sourceTable.trim()}>Create</Button></>}>
        <div className="stack">
          <Input label="Code" value={code} onChange={(e) => setCode(e.target.value)} placeholder="e.g. daily_appointments" />
          <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Daily appointments" />
          <Select label="Domain" value={domain} onChange={(e) => setDomain(e.target.value)}>
            <option value="operational">Operational</option>
            <option value="clinical">Clinical</option>
            <option value="financial">Financial</option>
          </Select>
          <Input label="Source table" value={sourceTable} onChange={(e) => setSourceTable(e.target.value)} placeholder="e.g. appointments" />
          <Input label="Date column" value={dateColumn} onChange={(e) => setDateColumn(e.target.value)} />
          <Select label="Aggregation" value={aggregation} onChange={(e) => setAggregation(e.target.value)}>
            <option value="count">Count</option>
            <option value="sum">Sum</option>
            <option value="avg">Average</option>
          </Select>
        </div>
      </Dialog>
    </Card>
  );
}

function DashboardTab({ fac }: { fac: string | null }) {
  const dashboards = useFetch(() => analyticsApi.dashboards(fac), [fac]);

  if (dashboards.loading) return <Spinner />;
  if (dashboards.error) return <ErrorState error={dashboards.error} onRetry={() => void dashboards.refresh()} />;

  const data = dashboards.data ?? [];

  return (
    <Card title="Dashboards">
      {data.length === 0 ? (
        <EmptyState title="No dashboards" body="Dashboards are configured by administrators." />
      ) : (
        <table className="data-table">
          <thead><tr><th>Code</th><th>Name</th><th>Role gate</th><th>Status</th></tr></thead>
          <tbody>
            {data.map((d) => (
              <tr key={d.id}>
                <td className="mono">{d.code}</td>
                <td>{d.name}</td>
                <td>{d.roleGate ?? '—'}</td>
                <td><span className={`status-chip status-chip--${d.isActive ? 'success' : 'neutral'}`}>{d.isActive ? 'Active' : 'Inactive'}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  );
}

function TemplateTab({ fac, canManage, setNotice }: { fac: string | null; canManage: boolean; setNotice: (n: any) => void }) {
  const [createOpen, setCreateOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const templates = useFetch(() => analyticsApi.reportTemplates(fac), [fac]);

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [category, setCategory] = useState('operational');
  const [scope, setScope] = useState('facility');

  const handleCreate = async () => {
    setBusy(true);
    try {
      await analyticsApi.storeReportTemplate({ code, name, category, scope }, fac);
      setNotice({ tone: 'success', text: 'Report template created.' });
      setCreateOpen(false);
      setCode('');
      setName('');
      void templates.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed.' });
    } finally {
      setBusy(false);
    }
  };

  if (templates.loading) return <Spinner />;
  if (templates.error) return <ErrorState error={templates.error} onRetry={() => void templates.refresh()} />;

  const data = templates.data ?? [];

  return (
    <Card title="Report Templates">
      {canManage && (
        <div className="row mb-4"><Button onClick={() => setCreateOpen(true)}>Add template</Button></div>
      )}
      {data.length === 0 ? (
        <EmptyState title="No report templates" body="Create templates to define reusable reports." />
      ) : (
        <table className="data-table">
          <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Scope</th><th>Status</th></tr></thead>
          <tbody>
            {data.map((t) => (
              <tr key={t.id}>
                <td className="mono">{t.code}</td>
                <td>{t.name}</td>
                <td>{t.category}</td>
                <td>{t.scope}</td>
                <td><span className={`status-chip status-chip--${t.isActive ? 'success' : 'neutral'}`}>{t.isActive ? 'Active' : 'Inactive'}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} title="Add report template"
        footer={<><Button variant="ghost" onClick={() => setCreateOpen(false)}>Cancel</Button><Button onClick={() => void handleCreate()} loading={busy} disabled={!code.trim() || !name.trim()}>Create</Button></>}>
        <div className="stack">
          <Input label="Code" value={code} onChange={(e) => setCode(e.target.value)} placeholder="e.g. daily_summary" />
          <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Daily summary" />
          <Select label="Category" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="operational">Operational</option>
            <option value="clinical">Clinical</option>
            <option value="financial">Financial</option>
            <option value="pharmacy">Pharmacy</option>
          </Select>
          <Select label="Scope" value={scope} onChange={(e) => setScope(e.target.value)}>
            <option value="facility">Facility</option>
            <option value="organization">Organization</option>
          </Select>
        </div>
      </Dialog>
    </Card>
  );
}

function RunsTab({ fac, canManage, setNotice }: { fac: string | null; canManage: boolean; setNotice: (n: any) => void }) {
  const [runBusy, setRunBusy] = useState(false);

  const runs = useFetch(() => analyticsApi.reportRuns(fac), [fac]);
  const templates = useFetch(() => analyticsApi.reportTemplates(fac), [fac]);

  const handleRun = async (templateId: string) => {
    setRunBusy(true);
    try {
      await analyticsApi.runReport({ templateId }, fac);
      setNotice({ tone: 'success', text: 'Report started.' });
      void runs.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed.' });
    } finally {
      setRunBusy(false);
    }
  };

  const handleExport = async (templateId: string) => {
    setRunBusy(true);
    try {
      await analyticsApi.exportReport({ templateId, format: 'csv' }, fac);
      setNotice({ tone: 'success', text: 'Export started.' });
      void runs.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed.' });
    } finally {
      setRunBusy(false);
    }
  };

  if (runs.loading || templates.loading) return <Spinner />;
  if (runs.error) return <ErrorState error={runs.error} onRetry={() => void runs.refresh()} />;

  const templateList = templates.data ?? [];
  const runList = runs.data ?? [];

  return (
    <div className="stack">
      {canManage && templateList.length > 0 && (
        <Card title="Run a report">
          <div className="stack">
            {templateList.filter((t) => t.isActive).map((t) => (
              <div key={t.id} className="row" style={{ justifyContent: 'space-between', alignItems: 'center' }}>
                <span>{t.name} <span className="muted small">({t.category})</span></span>
                <div className="row">
                  <Button size="sm" onClick={() => void handleRun(t.id)} loading={runBusy}>Run</Button>
                  <Button size="sm" variant="ghost" onClick={() => void handleExport(t.id)} loading={runBusy}>Export CSV</Button>
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      <Card title="Report runs">
        {runList.length === 0 ? (
          <EmptyState title="No report runs" body="Run a report to see results here." />
        ) : (
          <table className="data-table">
            <thead><tr><th>Template</th><th>Started</th><th>Completed</th><th>Rows</th><th>Status</th><th>Export</th></tr></thead>
            <tbody>
              {runList.map((r) => (
                <tr key={r.id}>
                  <td>{r.templateCode ?? '—'}</td>
                  <td className="num">{new Date(r.runAt).toLocaleString()}</td>
                  <td className="num">{r.completedAt ? new Date(r.completedAt).toLocaleString() : '—'}</td>
                  <td className="num">{r.rowCount ?? '—'}</td>
                  <td><span className={`status-chip status-chip--${r.status === 'completed' ? 'success' : r.status === 'failed' ? 'danger' : 'info'}`}>{r.status}</span></td>
                  <td>{r.isExport ? <span className="muted small">{r.exportFormat}</span> : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  );
}
