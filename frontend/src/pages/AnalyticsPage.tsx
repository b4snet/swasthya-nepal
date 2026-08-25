import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { analyticsApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, EmptyState } from '../components/ui';
import { dashboardApi } from '../api/dashboard';
import '../pages/analytics-cmd.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface KpiDefinition {
  id: string;
  code: string;
  name: string;
  domain: string;
  sourceTable: string;
  aggregation: string;
  unit: string | null;
  status: string;
}

interface Dashboard {
  id: string;
  name: string;
  description: string | null;
  kpis: Array<{ id: string; name: string; domain: string }>;
  status: string;
}

interface ReportTemplate {
  id: string;
  code: string;
  name: string;
  category: string;
  scope: string;
  status: string;
}

/* Domain summary types from backend AnalyticsService */
interface OperationalSummary {
  patientsRegisteredToday: number;
  appointmentsToday: number;
  activeEncounters: number;
  bedOccupancy: { occupied: number; total: number };
}
interface ClinicalSummary {
  encountersLast7Days: number;
  prescriptionsLast7Days: number;
  pendingCriticalValues: number;
  followUpsDueIn3Days: number;
}
interface FinancialSummary {
  revenueTodayMinor: number;
  outstandingMinor: number;
  pendingRefunds: number;
  chargesToday: number;
}
interface PharmacySummary {
  dispensedToday: number;
  lowStockItems: number;
  totalReturns: number;
}
interface LabSummary {
  ordersToday: number;
  pendingResults: number;
  criticalPending: number;
}
interface RadiologySummary {
  studiesToday: number;
  pendingReports: number;
}
interface ProcurementSummary {
  pendingRequests: number;
  openOrders: number;
  pendingReceipts: number;
}
interface HrSummary {
  activeStaff: number;
  pendingLeaveRequests: number;
}

/* ── Constants ───────────────────────────────────────────────────── */

const DOMAIN_COLORS: Record<string, { color: string; bg: string }> = {
  clinical: { color: '#10b981', bg: '#ecfdf5' },
  operational: { color: '#3b82f6', bg: '#dbeafe' },
  financial: { color: '#f59e0b', bg: '#fef3c7' },
  inventory: { color: '#8b5cf6', bg: '#f5f3ff' },
  workforce: { color: '#ec4899', bg: '#fce7f3' },
  patient_flow: { color: '#06b6d4', bg: '#cffafe' },
  quality: { color: '#ef4444', bg: '#fee2e2' },
};

function DomainBadge({ domain }: { domain: string }) {
  const c = DOMAIN_COLORS[domain] ?? { color: '#6b7280', bg: '#f3f4f6' };
  return <span className="analytics-badge" style={{ color: c.color, backgroundColor: c.bg }}>{domain.replace(/_/g, ' ')}</span>;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function AnalyticsPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'command-center' | 'kpis' | 'reports' | 'dashboards' | 'exports'>('command-center');
  const [busy, setBusy] = useState(false);

  // Filter
  const [dateRange, setDateRange] = useState('today');

  // Data fetching
  const kpis = useFetch(
    () => analyticsApi.kpiDefinitions(fac).catch(() => []),
    [fac],
  );

  const dashboards = useFetch(
    () => analyticsApi.dashboards(fac).catch(() => []),
    [fac],
  );

  const reportTemplates = useFetch(
    () => analyticsApi.reportTemplates(fac).catch(() => []),
    [fac],
  );

  const allKpis = useMemo(() => (kpis.data ?? []) as KpiDefinition[], [kpis.data]);
  const allDashboards = useMemo(() => (dashboards.data ?? []) as unknown as Dashboard[], [dashboards.data]);
  const allReports = useMemo(() => (reportTemplates.data ?? []) as unknown as ReportTemplate[], [reportTemplates.data]);

  // Domain summaries — real data from the backend
  const opsData = useFetch(() => dashboardApi.domainSummary('operational', fac).catch(() => ({})), [fac]);
  const clinData = useFetch(() => dashboardApi.domainSummary('clinical', fac).catch(() => ({})), [fac]);
  const finData = useFetch(() => dashboardApi.domainSummary('financial', fac).catch(() => ({})), [fac]);
  const pharmData = useFetch(() => dashboardApi.domainSummary('pharmacy', fac).catch(() => ({})), [fac]);
  const labData = useFetch(() => dashboardApi.domainSummary('laboratory', fac).catch(() => ({})), [fac]);
  const radData = useFetch(() => dashboardApi.domainSummary('radiology', fac).catch(() => ({})), [fac]);
  const procData = useFetch(() => dashboardApi.domainSummary('procurement', fac).catch(() => ({})), [fac]);
  const hrData = useFetch(() => dashboardApi.domainSummary('hr', fac).catch(() => ({})), [fac]);

  const ops = useMemo(() => (opsData.data ?? {}) as OperationalSummary, [opsData.data]);
  const clin = useMemo(() => (clinData.data ?? {}) as ClinicalSummary, [clinData.data]);
  const fin = useMemo(() => (finData.data ?? {}) as FinancialSummary, [finData.data]);
  const pharm = useMemo(() => (pharmData.data ?? {}) as PharmacySummary, [pharmData.data]);
  const lab = useMemo(() => (labData.data ?? {}) as LabSummary, [labData.data]);
  const rad = useMemo(() => (radData.data ?? {}) as RadiologySummary, [radData.data]);
  const proc = useMemo(() => (procData.data ?? {}) as ProcurementSummary, [procData.data]);
  const hr = useMemo(() => (hrData.data ?? {}) as HrSummary, [hrData.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Domain KPI counts
  const kpiDomainCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    allKpis.forEach(k => { counts[k.domain] = (counts[k.domain] || 0) + 1; });
    return counts;
  }, [allKpis]);

  const handleRunReport = useCallback(async (templateId: string) => {
    await go(() => analyticsApi.runReport({ templateId }, fac));
  }, [fac, go]);

  const handleExportReport = useCallback(async (templateId: string, format: string) => {
    await go(() => analyticsApi.exportReport({ templateId, format }, fac));
  }, [fac, go]);

  return (
    <div className="page analytics-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Analytics & Command Center</h1>
          <p className="page__subtitle">Enterprise dashboards, KPIs, reports, hospital operations</p>
        </div>
        <div className="analytics-actions">
          <select className="analytics-select" value={dateRange} onChange={e => setDateRange(e.target.value)}>
            <option value="today">Today</option>
            <option value="week">This Week</option>
            <option value="month">This Month</option>
            <option value="quarter">This Quarter</option>
            <option value="year">This Year</option>
          </select>
          <Button variant="ghost" onClick={() => { kpis.refresh(); dashboards.refresh(); reportTemplates.refresh(); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="analytics-census">
        <div className="analytics-census-card analytics-census-card--kpis">
          <span className="analytics-census-value">{allKpis.length}</span>
          <span className="analytics-census-label">KPI Definitions</span>
        </div>
        <div className="analytics-census-card analytics-census-card--dashboards">
          <span className="analytics-census-value">{allDashboards.length}</span>
          <span className="analytics-census-label">Dashboards</span>
        </div>
        <div className="analytics-census-card analytics-census-card--reports">
          <span className="analytics-census-value">{allReports.length}</span>
          <span className="analytics-census-label">Report Templates</span>
        </div>
        <div className="analytics-census-card analytics-census-card--clinical">
          <span className="analytics-census-value">{kpiDomainCounts.clinical ?? 0}</span>
          <span className="analytics-census-label">Clinical KPIs</span>
        </div>
        <div className="analytics-census-card analytics-census-card--operational">
          <span className="analytics-census-value">{kpiDomainCounts.operational ?? 0}</span>
          <span className="analytics-census-label">Operational KPIs</span>
        </div>
        <div className="analytics-census-card analytics-census-card--financial">
          <span className="analytics-census-value">{kpiDomainCounts.financial ?? 0}</span>
          <span className="analytics-census-label">Financial KPIs</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="analytics-tabs">
        {(['command-center', 'kpis', 'reports', 'dashboards', 'exports'] as const).map(t => (
          <button key={t} className={`analytics-tab ${activeTab === t ? 'analytics-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'command-center' ? 'Command Center' : t === 'kpis' ? 'KPIs' : t === 'reports' ? 'Reports' : t === 'dashboards' ? 'Dashboards' : 'Exports'}
          </button>
        ))}
      </div>

      {/* ── Command Center Tab ─────────────────────────────── */}
      {activeTab === 'command-center' && (
        <div className="analytics-command-center">
          {/* Hospital Operations Overview */}
          <Card className="analytics-section-card">
            <div className="analytics-section-header">
              <h3>Hospital Operations — {dateRange === 'today' ? 'Today' : dateRange.charAt(0).toUpperCase() + dateRange.slice(1)}</h3>
            </div>
            <div className="analytics-ops-grid">
              <div className="analytics-ops-card">
                <span className="analytics-ops-label">Patients Today</span>
                <span className="analytics-ops-value">{ops.patientsRegisteredToday ?? 0}</span>
                <span className="analytics-ops-sub">New registrations</span>
              </div>
              <div className="analytics-ops-card">
                <span className="analytics-ops-label">Appointments</span>
                <span className="analytics-ops-value">{ops.appointmentsToday ?? 0}</span>
                <span className="analytics-ops-sub">Scheduled today</span>
              </div>
              <div className="analytics-ops-card">
                <span className="analytics-ops-label">Active Encounters</span>
                <span className="analytics-ops-value">{ops.activeEncounters ?? 0}</span>
                <span className="analytics-ops-sub">Open consultations</span>
              </div>
              <div className="analytics-ops-card">
                <span className="analytics-ops-label">Bed Occupancy</span>
                <span className="analytics-ops-value">{ops.bedOccupancy?.total ? Math.round((ops.bedOccupancy.occupied / ops.bedOccupancy.total) * 100) + '%' : '—'}</span>
                <span className="analytics-ops-sub">{ops.bedOccupancy?.occupied ?? 0} / {ops.bedOccupancy?.total ?? 0} beds</span>
              </div>
              <div className="analytics-ops-card">
                <span className="analytics-ops-label">Encounters (7d)</span>
                <span className="analytics-ops-value">{clin.encountersLast7Days ?? 0}</span>
                <span className="analytics-ops-sub">Last 7 days</span>
              </div>
              <div className="analytics-ops-card">
                <span className="analytics-ops-label">Active Staff</span>
                <span className="analytics-ops-value">{hr.activeStaff ?? 0}</span>
                <span className="analytics-ops-sub">On duty</span>
              </div>
            </div>
          </Card>

          {/* Department Workload */}
          <Card className="analytics-section-card">
            <div className="analytics-section-header">
              <h3>Department Workload</h3>
            </div>
            <div className="analytics-dept-grid">
              <div className="analytics-dept-card">
                <span className="analytics-dept-name">Laboratory</span>
                <div className="analytics-dept-bar">
                  <div className="analytics-dept-fill" style={{ width: '0%', backgroundColor: '#8b5cf6' }} />
                </div>
                <span className="analytics-dept-stat">{lab.pendingResults ?? 0} pending results</span>
              </div>
              <div className="analytics-dept-card">
                <span className="analytics-dept-name">Radiology</span>
                <div className="analytics-dept-bar">
                  <div className="analytics-dept-fill" style={{ width: '0%', backgroundColor: '#06b6d4' }} />
                </div>
                <span className="analytics-dept-stat">{rad.pendingReports ?? 0} pending reports</span>
              </div>
              <div className="analytics-dept-card">
                <span className="analytics-dept-name">Pharmacy</span>
                <div className="analytics-dept-bar">
                  <div className="analytics-dept-fill" style={{ width: '0%', backgroundColor: '#f59e0b' }} />
                </div>
                <span className="analytics-dept-stat">{pharm.dispensedToday ?? 0} dispensed today</span>
              </div>
              <div className="analytics-dept-card">
                <span className="analytics-dept-name">Blood Bank</span>
                <div className="analytics-dept-bar">
                  <div className="analytics-dept-fill" style={{ width: '0%', backgroundColor: '#ef4444' }} />
                </div>
                <span className="analytics-dept-stat">{proc.openOrders ?? 0} open orders</span>
              </div>
            </div>
          </Card>

          {/* Financial Summary */}
          <Card className="analytics-section-card">
            <div className="analytics-section-header">
              <h3>Financial Summary</h3>
            </div>
            <div className="analytics-fin-grid">
              <div className="analytics-fin-card">
                <span className="analytics-fin-label">Revenue Today</span>
                <span className="analytics-fin-value">NPR {((fin.revenueTodayMinor ?? 0) / 100).toLocaleString()}</span>
              </div>
              <div className="analytics-fin-card">
                <span className="analytics-fin-label">Outstanding</span>
                <span className="analytics-fin-value analytics-fin-value--warning">NPR {((fin.outstandingMinor ?? 0) / 100).toLocaleString()}</span>
              </div>
              <div className="analytics-fin-card">
                <span className="analytics-fin-label">Charges Today</span>
                <span className="analytics-fin-value analytics-fin-value--success">{fin.chargesToday ?? 0}</span>
              </div>
              <div className="analytics-fin-card">
                <span className="analytics-fin-label">Pending Refunds</span>
                <span className="analytics-fin-value">{fin.pendingRefunds ?? 0}</span>
              </div>
            </div>
          </Card>

          {/* Critical Events */}
          <Card className="analytics-section-card">
            <div className="analytics-section-header">
              <h3>Critical Events</h3>
            </div>
            <EmptyState title="Critical events" body="Critical lab results, imaging findings, and urgent alerts appear here." />
          </Card>
        </div>
      )}

      {/* ── KPIs Tab ──────────────────────────────────────── */}
      {activeTab === 'kpis' && (
        <Card className="analytics-section-card">
          <div className="analytics-section-header">
            <h3>KPI Definitions</h3>
          </div>
          {allKpis.length === 0 ? (
            <EmptyState title="No KPIs configured" body="Define KPIs to track hospital performance metrics." />
          ) : (
            <div className="analytics-table">
              <div className="analytics-table-header">
                <span>Code</span>
                <span>Name</span>
                <span>Domain</span>
                <span>Source</span>
                <span>Aggregation</span>
                <span>Unit</span>
              </div>
              {allKpis.map(k => (
                <div key={k.id} className="analytics-table-row">
                  <span className="analytics-mono">{k.code}</span>
                  <span className="analytics-name">{k.name}</span>
                  <DomainBadge domain={k.domain} />
                  <span className="analytics-mono">{k.sourceTable}</span>
                  <span>{k.aggregation}</span>
                  <span>{k.unit ?? '—'}</span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Reports Tab ───────────────────────────────────── */}
      {activeTab === 'reports' && (
        <Card className="analytics-section-card">
          <div className="analytics-section-header">
            <h3>Report Catalogue</h3>
          </div>
          {allReports.length === 0 ? (
            <EmptyState title="No report templates" body="Create report templates to generate hospital analytics." />
          ) : (
            <div className="analytics-report-grid">
              {allReports.map(r => (
                <div key={r.id} className="analytics-report-card">
                  <div className="analytics-report-header">
                    <span className="analytics-report-name">{r.name}</span>
                    <DomainBadge domain={r.category} />
                  </div>
                  <span className="analytics-report-code">{r.code}</span>
                  <span className="analytics-report-scope">Scope: {r.scope}</span>
                  <div className="analytics-report-actions">
                    <Button variant="ghost" size="sm" onClick={() => void handleRunReport(r.id)} loading={busy}>Run</Button>
                    <Button variant="ghost" size="sm" onClick={() => void handleExportReport(r.id, 'csv')}>Export CSV</Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Dashboards Tab ────────────────────────────────── */}
      {activeTab === 'dashboards' && (
        <Card className="analytics-section-card">
          <div className="analytics-section-header">
            <h3>Saved Dashboards</h3>
          </div>
          {allDashboards.length === 0 ? (
            <EmptyState title="No dashboards" body="Create dashboards to visualize hospital analytics." />
          ) : (
            <div className="analytics-dashboard-grid">
              {allDashboards.map(d => (
                <div key={d.id} className="analytics-dashboard-card">
                  <div className="analytics-dashboard-header">
                    <span className="analytics-dashboard-name">{d.name}</span>
                    <span className="analytics-dashboard-kpis">{d.kpis?.length ?? 0} KPIs</span>
                  </div>
                  {d.description && <p className="analytics-dashboard-desc">{d.description}</p>}
                  <div className="analytics-dashboard-kpi-list">
                    {(d.kpis ?? []).slice(0, 5).map(k => (
                      <span key={k.id} className="analytics-kpi-tag">{k.name}</span>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Exports Tab ───────────────────────────────────── */}
      {activeTab === 'exports' && (
        <Card className="analytics-section-card">
          <div className="analytics-section-header">
            <h3>Report Exports</h3>
          </div>
          <EmptyState title="Export center" body="Run and export reports in CSV, spreadsheet, or PDF format." />
        </Card>
      )}
    </div>
  );
}
