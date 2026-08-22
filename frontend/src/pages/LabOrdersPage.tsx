/**
 * Laboratory Orders Worklist
 *
 * Role-aware worklist for laboratory technicians and supervisors.
 * Shows orders by status with actions for the current lifecycle step.
 *
 * Lifecycle: ordered → collected → processing → results_entered → verified → reported
 */

import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useAccess } from '../auth/useAccess';
import { labOrdersApi, criticalValueApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Alert,
  Card,
  EmptyState,
  ErrorState,
  Input,
  Spinner,
  StatusChip,
  Tabs,
  formatDateTime,
} from '../components/ui';
import { ApiError } from '../api/client';
import {
  Clock,
  AlertTriangle,
} from 'lucide-react';
import './lab-orders.css';

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/* ════════════════════════════════════════════════════════════════════════════
   MAIN PAGE
   ════════════════════════════════════════════════════════════════════════════ */

export function LabOrdersPage() {
  const { selectedFacilityId } = useTenant();
  const access = useAccess();
  const fac = selectedFacilityId;
  const [tab, setTab] = useState('all');
  const [date, setDate] = useState(today());
  const [search, setSearch] = useState('');

  const orders = useFetch(() => labOrdersApi.forPatient('', fac), [fac]);
  const criticalValues = useFetch(() => criticalValueApi.list(fac), [fac]);

  const canVerify = access.hasAnyRole('superadmin', 'org_admin', 'hospital_admin', 'lab_supervisor');
  const canProcess = access.hasAnyRole('superadmin', 'org_admin', 'hospital_admin', 'lab_technician', 'lab_supervisor');

  const allOrders = (orders.data ?? []) as any[];

  /* ── Filter by status tab ── */
  const filteredOrders = allOrders.filter((o: any) => {
    if (tab !== 'all' && o.status !== tab) return false;
    if (search && !o.patientName?.toLowerCase().includes(search.toLowerCase()) && !o.testName?.toLowerCase().includes(search.toLowerCase())) return false;
    return true;
  });

  /* ── Status counts ── */
  const statusCounts = allOrders.reduce(
    (acc: Record<string, number>, o: any) => {
      acc[o.status] = (acc[o.status] || 0) + 1;
      return acc;
    },
    {} as Record<string, number>,
  );

  const tabs = [
    { id: 'all', label: `All (${allOrders.length})` },
    { id: 'ordered', label: `New (${statusCounts['ordered'] ?? 0})` },
    { id: 'collected', label: `Collected (${statusCounts['collected'] ?? 0})` },
    { id: 'processing', label: `Processing (${statusCounts['processing'] ?? 0})` },
    { id: 'results_entered', label: `Results (${statusCounts['results_entered'] ?? 0})` },
    { id: 'verified', label: `Verified (${statusCounts['verified'] ?? 0})` },
    { id: 'reported', label: `Reported (${statusCounts['reported'] ?? 0})` },
  ];

  return (
    <div className="page">
      {/* ── Header ── */}
      <div className="page__head">
        <div className="page__title">
          <h1>Laboratory Orders</h1>
          <span className="page__sub">Worklist · {allOrders.length} orders</span>
        </div>
      </div>

      {/* ── Critical values alert ── */}
      {(criticalValues.data ?? []).length > 0 && (
        <Alert tone="danger">
          <AlertTriangle size={16} style={{ marginRight: 6 }} />
          {(criticalValues.data as any[]).length} critical value(s) require acknowledgement
        </Alert>
      )}

      {/* ── Filters ── */}
      <div className="lab-orders__filters">
        <Input
          label="Search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search patient or test…"
          style={{ maxWidth: 280 }}
        />
        <input
          type="date"
          className="input"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          style={{ width: 160 }}
        />
      </div>

      {/* ── Tabs ── */}
      <Tabs tabs={tabs} active={tab} onChange={(t) => setTab(t)} />

      {/* ── Content ── */}
      {orders.loading ? (
        <Spinner label="Loading orders…" />
      ) : orders.error ? (
        <ErrorState error={orders.error} onRetry={() => void orders.refresh()} />
      ) : filteredOrders.length === 0 ? (
        <EmptyState
          title="No orders found"
          body={tab === 'all' ? "No laboratory orders in the system." : `No ${tab.replace('_', ' ')} orders.`}
        />
      ) : (
        <Card>
          <table className="data-table lab-orders__table">
            <thead>
              <tr>
                <th>Ordered</th>
                <th>Patient</th>
                <th>Test</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Ordered By</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {filteredOrders.map((o: any) => (
                <tr key={o.id} className={`lab-orders__row lab-orders__row--${o.status}`}>
                  <td data-label="Ordered" className="mono">
                    <Clock size={14} style={{ marginRight: 4, opacity: 0.5 }} />
                    {formatDateTime(o.createdAt)}
                  </td>
                  <td data-label="Patient">
                    <Link to={`/clinical/patients/${o.patientId}`} className="lab-orders__patient">
                      {o.patientName ?? '—'}
                    </Link>
                    <span className="mono" style={{ fontSize: 11, color: 'var(--text-tertiary)' }}>
                      {o.patientMrn ?? ''}
                    </span>
                  </td>
                  <td data-label="Test">{o.testName ?? o.name ?? '—'}</td>
                  <td data-label="Priority">
                    <StatusChip
                      tone={o.priority === 'stat' ? 'danger' : o.priority === 'urgent' ? 'warning' : 'neutral'}
                      label={o.priority ?? 'routine'}
                    />
                  </td>
                  <td data-label="Status">
                    <StatusChip
                      tone={getStatusTone(o.status)}
                      label={formatStatus(o.status)}
                    />
                  </td>
                  <td data-label="Ordered By">{o.orderedByName ?? '—'}</td>
                  <td>
                    <div style={{ display: 'flex', gap: 6 }}>
                      {canProcess && o.status === 'ordered' && (
                        <ActionButton
                          action="collect"
                          orderId={o.id}
                          facilityId={fac}
                          onDone={() => void orders.refresh()}
                          label="Collect"
                        />
                      )}
                      {canProcess && o.status === 'collected' && (
                        <ActionButton
                          action="process"
                          orderId={o.id}
                          facilityId={fac}
                          onDone={() => void orders.refresh()}
                          label="Process"
                        />
                      )}
                      {canProcess && o.status === 'processing' && (
                        <ActionButton
                          action="enterResults"
                          orderId={o.id}
                          facilityId={fac}
                          onDone={() => void orders.refresh()}
                          label="Enter Results"
                        />
                      )}
                      {canVerify && o.status === 'results_entered' && (
                        <ActionButton
                          action="verify"
                          orderId={o.id}
                          facilityId={fac}
                          onDone={() => void orders.refresh()}
                          label="Verify"
                        />
                      )}
                      {canVerify && o.status === 'verified' && (
                        <ActionButton
                          action="report"
                          orderId={o.id}
                          facilityId={fac}
                          onDone={() => void orders.refresh()}
                          label="Release"
                        />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   ACTION BUTTON
   ════════════════════════════════════════════════════════════════════════════ */

function ActionButton({
  action,
  orderId,
  facilityId,
  onDone,
  label,
}: {
  action: string;
  orderId: string;
  facilityId: string | null;
  onDone: () => void;
  label: string;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleClick = async () => {
    setBusy(true);
    setError(null);
    try {
      switch (action) {
        case 'collect':
          await labOrdersApi.collect(orderId, facilityId);
          break;
        case 'process':
          await labOrdersApi.process(orderId, facilityId);
          break;
        case 'enterResults':
          await labOrdersApi.enterResults(orderId, { items: [] }, facilityId);
          break;
        case 'verify':
          await labOrdersApi.verify(orderId, facilityId);
          break;
        case 'report':
          await labOrdersApi.report(orderId, facilityId);
          break;
      }
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Action failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <button
        type="button"
        className="btn btn--sm btn--secondary"
        onClick={() => void handleClick()}
        disabled={busy}
      >
        {busy ? '…' : label}
      </button>
      {error && <span className="lab-orders__error">{error}</span>}
    </>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════════════════════════════════════════ */

function getStatusTone(status: string): 'success' | 'info' | 'warning' | 'danger' | 'neutral' {
  switch (status) {
    case 'reported': return 'success';
    case 'verified': return 'info';
    case 'results_entered': return 'warning';
    case 'processing': return 'info';
    case 'collected': return 'info';
    case 'ordered': return 'neutral';
    default: return 'neutral';
  }
}

function formatStatus(status: string): string {
  return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
