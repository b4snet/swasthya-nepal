import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../../context/TenantContext';
import { useFetch } from '../../hooks/useFetch';
import { labOrdersApi, labTestsApi, criticalValueApi } from '../../api/endpoints';
import { ApiError } from '../../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../../components/ui';
import '../modules/lab-lis.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface LabOrder {
  id: string;
  patientId: string;
  encounterId: string;
  status: string;
  priority: string;
  clinicalIndication: string | null;
  orderedAt: string;
  collectedAt: string | null;
  resultsEnteredAt: string | null;
  verifiedAt: string | null;
  reportedAt: string | null;
  items: Array<{
    id: string;
    testName: string;
    resultValue: string | null;
    resultUnit: string | null;
    referenceRange: string | null;
  }>;
}

interface LabTest {
  id: string;
  code: string;
  name: string;
  category: string;
  specimenType: string;
  unit: string;
  referenceRange: string;
  status: string;
}

interface CriticalEvent {
  id: string;
  labOrderId: string;
  patientId: string;
  testName: string;
  value: string;
  severity: string;
  status: string;
  createdAt: string;
}

/* ── Constants ───────────────────────────────────────────────────── */

const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  ordered: { label: 'Ordered', color: '#f59e0b', bg: '#fef3c7' },
  collected: { label: 'Collected', color: '#3b82f6', bg: '#dbeafe' },
  processing: { label: 'Processing', color: '#8b5cf6', bg: '#f5f3ff' },
  results_entered: { label: 'Results Entered', color: '#ea580c', bg: '#ffedd5' },
  verified: { label: 'Verified', color: '#10b981', bg: '#ecfdf5' },
  reported: { label: 'Reported', color: '#059669', bg: '#ecfdf5' },
  correcting: { label: 'Correcting', color: '#f59e0b', bg: '#fef3c7' },
};

const PRIORITY_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  routine: { label: 'Routine', color: '#6b7280', bg: '#f3f4f6' },
  urgent: { label: 'Urgent', color: '#f59e0b', bg: '#fef3c7' },
  stat: { label: 'STAT', color: '#dc2626', bg: '#fee2e2' },
};

const CRITICAL_SEVERITY: Record<string, { color: string; bg: string }> = {
  critical: { color: '#dc2626', bg: '#fee2e2' },
  high: { color: '#ea580c', bg: '#ffedd5' },
  moderate: { color: '#f59e0b', bg: '#fef3c7' },
};

/* ── Main Component ──────────────────────────────────────────────── */

export function LaboratoryDashboard() {
  const { organizationId, selectedFacilityId: facilityId } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'orders' | 'specimens' | 'results' | 'critical' | 'catalogue'>('orders');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [statusFilter, setStatusFilter] = useState('');

  // Fetch lab orders (using patient endpoint as proxy — we'll fetch all)
  const labOrders = useFetch(
    () => facilityId ? labOrdersApi.forPatient('all', facilityId) : Promise.resolve([]),
    [facilityId],
  );

  const labTests = useFetch(
    () => organizationId ? labTestsApi.list(organizationId, facilityId) : Promise.resolve([]),
    [organizationId, facilityId],
  );

  const criticalEvents = useFetch(
    () => criticalValueApi.list(facilityId),
    [facilityId],
  );

  // Result entry form
  const [resultOrderId, setResultOrderId] = useState('');
  const [resultItems, setResultItems] = useState<Array<{ labOrderId: string; resultValue: string; resultUnit: string; referenceRange: string }>>([
    { labOrderId: '', resultValue: '', resultUnit: '', referenceRange: '' },
  ]);

  const go = async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  };

  const refresh = () => { void labOrders.refresh(); void criticalEvents.refresh(); void labTests.refresh(); };

  const allOrders = (labOrders.data ?? []) as unknown as LabOrder[];
  const allTests = (labTests.data ?? []) as unknown as LabTest[];
  const allCritical = (criticalEvents.data ?? []) as unknown as CriticalEvent[];

  // Census
  const census = useMemo(() => {
    const total = allOrders.length;
    const ordered = allOrders.filter(o => o.status === 'ordered').length;
    const collected = allOrders.filter(o => o.status === 'collected').length;
    const processing = allOrders.filter(o => o.status === 'processing').length;
    const resultsEntered = allOrders.filter(o => o.status === 'results_entered').length;
    const verified = allOrders.filter(o => o.status === 'verified').length;
    const reported = allOrders.filter(o => o.status === 'reported').length;
    const criticalCount = allCritical.filter(c => c.status !== 'acknowledged').length;
    return { total, ordered, collected, processing, resultsEntered, verified, reported, criticalCount };
  }, [allOrders, allCritical]);

  // Filtered orders
  const filteredOrders = useMemo(() => {
    if (!statusFilter) return allOrders;
    return allOrders.filter(o => o.status === statusFilter);
  }, [allOrders, statusFilter]);

  if (labOrders.loading) return <SkeletonTable rows={6} cols={5} />;

  return (
    <div className="page lab-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Laboratory Information System</h1>
          <p className="page__subtitle">Orders, specimens, results, verification, and critical values</p>
        </div>
        <div className="lab-actions">
          <Button variant="ghost" onClick={() => refresh()}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="lab-census">
        <div className="lab-census-card lab-census-card--total">
          <span className="lab-census-value">{census.total}</span>
          <span className="lab-census-label">Total Orders</span>
        </div>
        <div className="lab-census-card lab-census-card--ordered">
          <span className="lab-census-value">{census.ordered}</span>
          <span className="lab-census-label">Awaiting Collection</span>
        </div>
        <div className="lab-census-card lab-census-card--collected">
          <span className="lab-census-value">{census.collected}</span>
          <span className="lab-census-label">Collected</span>
        </div>
        <div className="lab-census-card lab-census-card--processing">
          <span className="lab-census-value">{census.processing}</span>
          <span className="lab-census-label">Processing</span>
        </div>
        <div className="lab-census-card lab-census-card--results">
          <span className="lab-census-value">{census.resultsEntered}</span>
          <span className="lab-census-label">Awaiting Verification</span>
        </div>
        <div className="lab-census-card lab-census-card--critical">
          <span className="lab-census-value" style={{ color: census.criticalCount > 0 ? '#dc2626' : undefined }}>
            {census.criticalCount}
          </span>
          <span className="lab-census-label">Critical Values</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="lab-tabs">
        <button className={`lab-tab ${activeTab === 'orders' ? 'lab-tab--active' : ''}`} onClick={() => setActiveTab('orders')}>
          Orders ({census.total})
        </button>
        <button className={`lab-tab ${activeTab === 'specimens' ? 'lab-tab--active' : ''}`} onClick={() => setActiveTab('specimens')}>
          Specimens
        </button>
        <button className={`lab-tab ${activeTab === 'results' ? 'lab-tab--active' : ''}`} onClick={() => setActiveTab('results')}>
          Results
        </button>
        <button className={`lab-tab ${activeTab === 'critical' ? 'lab-tab--active' : ''}`} onClick={() => setActiveTab('critical')}>
          Critical Values {census.criticalCount > 0 && <span className="lab-tab-badge lab-tab-badge--danger">{census.criticalCount}</span>}
        </button>
        <button className={`lab-tab ${activeTab === 'catalogue' ? 'lab-tab--active' : ''}`} onClick={() => setActiveTab('catalogue')}>
          Test Catalogue
        </button>
      </div>

      {/* ── Orders Tab ────────────────────────────────────── */}
      {activeTab === 'orders' && (
        <Card className="lab-section-card">
          <div className="lab-section-header">
            <h3>Lab Orders Worklist</h3>
            <div className="lab-section-actions">
              <Select label="Status" value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
                <option value="">All Statuses</option>
                <option value="ordered">Ordered</option>
                <option value="collected">Collected</option>
                <option value="processing">Processing</option>
                <option value="results_entered">Results Entered</option>
                <option value="verified">Verified</option>
                <option value="reported">Reported</option>
              </Select>
            </div>
          </div>
          {filteredOrders.length === 0 ? (
            <EmptyState title="No lab orders" body="Orders from clinicians appear here." />
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Patient</th>
                  <th>Tests</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Ordered</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredOrders.map(order => {
                  const statusCfg = STATUS_CONFIG[order.status] ?? STATUS_CONFIG.ordered;
                  const prioCfg = PRIORITY_CONFIG[order.priority] ?? PRIORITY_CONFIG.routine;
                  return (
                    <tr key={order.id}>
                      <td>
                        <Link to={`/patients/${order.patientId}`} className="lab-patient-link">
                          {order.patientId.slice(0, 8)}...
                        </Link>
                      </td>
                      <td className="lab-tests-cell">
                        {order.items?.map(i => i.testName).join(', ') || '—'}
                      </td>
                      <td>
                        <span className="lab-priority-badge" style={{ color: prioCfg.color, backgroundColor: prioCfg.bg }}>
                          {prioCfg.label}
                        </span>
                      </td>
                      <td>
                        <span className="lab-status-badge" style={{ color: statusCfg.color, backgroundColor: statusCfg.bg }}>
                          {statusCfg.label}
                        </span>
                      </td>
                      <td className="lab-time">{new Date(order.orderedAt).toLocaleString()}</td>
                      <td>
                        <div className="lab-row-actions">
                          {order.status === 'ordered' && (
                            <Button variant="ghost" size="sm" onClick={() => void go(() => labOrdersApi.collect(order.id).then(() => refresh()))}>
                              Collect
                            </Button>
                          )}
                          {order.status === 'collected' && (
                            <Button variant="ghost" size="sm" onClick={() => void go(() => labOrdersApi.process(order.id).then(() => refresh()))}>
                              Process
                            </Button>
                          )}
                          {order.status === 'processing' && (
                            <Button variant="ghost" size="sm" onClick={() => { setResultOrderId(order.id); setResultItems([{ labOrderId: order.id, resultValue: '', resultUnit: '', referenceRange: '' }]); setDlg('result'); }}>
                              Enter Results
                            </Button>
                          )}
                          {order.status === 'results_entered' && (
                            <Button variant="ghost" size="sm" onClick={() => void go(() => labOrdersApi.verify(order.id).then(() => refresh()))}>
                              Verify
                            </Button>
                          )}
                          {order.status === 'verified' && (
                            <Button variant="ghost" size="sm" onClick={() => void go(() => labOrdersApi.report(order.id).then(() => refresh()))}>
                              Report
                            </Button>
                          )}
                          <Link to={`/encounters/${order.encounterId}`}>
                            <Button variant="ghost" size="sm">Encounter</Button>
                          </Link>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Specimens Tab ─────────────────────────────────── */}
      {activeTab === 'specimens' && (
        <Card className="lab-section-card">
          <div className="lab-section-header">
            <h3>Specimen Tracking</h3>
          </div>
          <EmptyState title="Specimen tracking" body="Specimens are tracked through collection → accession → processing → completion." />
        </Card>
      )}

      {/* ── Results Tab ───────────────────────────────────── */}
      {activeTab === 'results' && (
        <Card className="lab-section-card">
          <div className="lab-section-header">
            <h3>Result Verification</h3>
          </div>
          {allOrders.filter(o => o.status === 'results_entered').length === 0 ? (
            <EmptyState title="No results awaiting verification" body="Enter results from the Orders tab to see them here." />
          ) : (
            <div className="lab-result-list">
              {allOrders.filter(o => o.status === 'results_entered').map(order => (
                <div key={order.id} className="lab-result-item">
                  <div className="lab-result-info">
                    <span className="lab-result-patient">{order.patientId.slice(0, 8)}...</span>
                    <span className="lab-result-tests">{order.items?.map(i => `${i.testName}: ${i.resultValue ?? '—'} ${i.resultUnit ?? ''}`).join(', ')}</span>
                  </div>
                  <Button variant="ghost" size="sm" onClick={() => void go(() => labOrdersApi.verify(order.id).then(() => refresh()))}>
                    Verify
                  </Button>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Critical Values Tab ───────────────────────────── */}
      {activeTab === 'critical' && (
        <Card className="lab-section-card">
          <div className="lab-section-header">
            <h3>Critical Values</h3>
            <span className="lab-badge lab-badge--danger">{census.criticalCount}</span>
          </div>
          {allCritical.length === 0 ? (
            <EmptyState title="No critical values" body="All critical values have been acknowledged." />
          ) : (
            <div className="lab-critical-list">
              {allCritical.map(evt => {
                const sevCfg = CRITICAL_SEVERITY[evt.severity] ?? CRITICAL_SEVERITY.critical;
                return (
                  <div key={evt.id} className="lab-critical-item" style={{ borderLeftColor: sevCfg.color }}>
                    <div className="lab-critical-header">
                      <span className="lab-critical-test">{evt.testName}</span>
                      <span className="lab-critical-value" style={{ color: sevCfg.color }}>{evt.value}</span>
                      <span className="lab-critical-severity" style={{ color: sevCfg.color, backgroundColor: sevCfg.bg }}>
                        {evt.severity}
                      </span>
                    </div>
                    <div className="lab-critical-meta">
                      <span>Patient: {evt.patientId.slice(0, 8)}...</span>
                      <span>{new Date(evt.createdAt).toLocaleString()}</span>
                      <span className="lab-critical-status">{evt.status}</span>
                    </div>
                    {evt.status !== 'acknowledged' && (
                      <div className="lab-critical-actions">
                        <Button variant="ghost" size="sm" onClick={() => void go(() => criticalValueApi.acknowledge(evt.id).then(() => refresh()))}>
                          Acknowledge
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => void go(() => criticalValueApi.escalate(evt.id, {}).then(() => refresh()))}>
                          Escalate
                        </Button>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* ── Test Catalogue Tab ────────────────────────────── */}
      {activeTab === 'catalogue' && (
        <Card className="lab-section-card">
          <div className="lab-section-header">
            <h3>Test Catalogue</h3>
            <span className="lab-badge">{allTests.length}</span>
          </div>
          {allTests.length === 0 ? (
            <EmptyState title="No tests configured" body="Configure laboratory tests in administration." />
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Category</th>
                  <th>Specimen Type</th>
                  <th>Unit</th>
                  <th>Reference Range</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {allTests.map(test => (
                  <tr key={test.id}>
                    <td className="font-medium">{test.code}</td>
                    <td>{test.name}</td>
                    <td>{test.category}</td>
                    <td>{test.specimenType}</td>
                    <td>{test.unit}</td>
                    <td>{test.referenceRange}</td>
                    <td>
                      <span className="lab-status-badge" style={{
                        color: test.status === 'active' ? '#10b981' : '#6b7280',
                        backgroundColor: test.status === 'active' ? '#ecfdf5' : '#f3f4f6',
                      }}>
                        {test.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Result Entry Dialog ───────────────────────────── */}
      {dlg === 'result' && (
        <Dialog open onClose={() => setDlg(null)} title="Enter Lab Results" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              const items = resultItems.filter(i => i.resultValue.trim());
              if (items.length === 0) { setError('Enter at least one result.'); return; }
              await go(async () => {
                await labOrdersApi.enterResults(resultOrderId, { items });
                setDlg(null); refresh();
              });
            }} loading={busy}>Submit Results</Button>
          </>
        }>
          <Alert tone="info">Enter result values for each test in this order.</Alert>
          {resultItems.map((item, idx) => (
            <div key={idx} className="lab-result-form-row">
              <Input label={`Order Item ID`} value={item.labOrderId} onChange={e => {
                const next = [...resultItems]; next[idx] = { ...next[idx], labOrderId: e.target.value }; setResultItems(next);
              }} placeholder="Item ID" />
              <Input label="Result Value" value={item.resultValue} onChange={e => {
                const next = [...resultItems]; next[idx] = { ...next[idx], resultValue: e.target.value }; setResultItems(next);
              }} placeholder="e.g. 12.5" />
              <Input label="Unit" value={item.resultUnit} onChange={e => {
                const next = [...resultItems]; next[idx] = { ...next[idx], resultUnit: e.target.value }; setResultItems(next);
              }} placeholder="g/dL" />
              <Input label="Reference Range" value={item.referenceRange} onChange={e => {
                const next = [...resultItems]; next[idx] = { ...next[idx], referenceRange: e.target.value }; setResultItems(next);
              }} placeholder="12.0-16.0" />
            </div>
          ))}
          <Button variant="ghost" size="sm" onClick={() => setResultItems([...resultItems, { labOrderId: '', resultValue: '', resultUnit: '', referenceRange: '' }])}>
            + Add Another Result
          </Button>
        </Dialog>
      )}
    </div>
  );
}
