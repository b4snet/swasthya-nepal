import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { financeApi, procurementApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/finance-cmd.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface Settlement {
  id: string;
  settlementDate: string;
  cashierId: string;
  expectedMinor: number;
  actualMinor: number;
  varianceMinor: number;
  status: string;
}

interface PurchaseOrder {
  id: string;
  poNumber: string;
  status: string;
  lines: Array<{ id: string; unitPriceMinor: number; quantityOrdered: number }>;
}

/* ── Constants ───────────────────────────────────────────────────── */

const INV_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  draft: { label: 'Draft', color: '#6b7280', bg: '#f3f4f6' },
  issued: { label: 'Issued', color: '#3b82f6', bg: '#dbeafe' },
  partially_paid: { label: 'Partial', color: '#f59e0b', bg: '#fef3c7' },
  paid: { label: 'Paid', color: '#10b981', bg: '#ecfdf5' },
  voided: { label: 'Voided', color: '#ef4444', bg: '#fee2e2' },
};

const SETTLEMENT_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  pending: { label: 'Pending', color: '#f59e0b', bg: '#fef3c7' },
  reconciled: { label: 'Reconciled', color: '#10b981', bg: '#ecfdf5' },
  variance: { label: 'Variance', color: '#ef4444', bg: '#fee2e2' },
};

function formatMoney(cents: number): string {
  return `Rs. ${(cents / 100).toLocaleString('en-NP', { minimumFractionDigits: 0 })}`;
}

function StatusBadge({ status, config }: { status: string; config: Record<string, { label: string; color: string; bg: string }> }) {
  const c = config[status] ?? { label: status.replace(/_/g, ' '), color: '#6b7280', bg: '#f3f4f6' };
  return <span className="fin-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function FinancePage() {
  const { selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'invoices' | 'payments' | 'aging' | 'settlements' | 'procurement'>('invoices');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Invoice filter
  const [invFilter, setInvFilter] = useState('all');

  // Settlement form
  const [settlementDate, setSettlementDate] = useState(new Date().toISOString().slice(0, 10));
  const [actualMinor, setActualMinor] = useState('');
  const [settlementNotes, setSettlementNotes] = useState('');

  // Data fetching
  const settlements = useFetch(
    () => fac ? financeApi.settlements(fac) : Promise.resolve([]),
    [fac],
  );

  const orders = useFetch(
    () => fac ? procurementApi.orders('', fac) : Promise.resolve([]),
    [fac],
  );

  const allSettlements = useMemo(() => (settlements.data ?? []) as Settlement[], [settlements.data]);
  const allOrders = useMemo(() => (orders.data ?? []) as PurchaseOrder[], [orders.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  const handleReconcile = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!settlementDate || !actualMinor) return;
    await go(() => financeApi.reconcileSettlement({
      settlementDate,
      actualMinor: parseInt(actualMinor),
      notes: settlementNotes || undefined,
    }, fac));
    setDlg(null); setActualMinor(''); setSettlementNotes('');
    settlements.refresh();
  }, [settlementDate, actualMinor, settlementNotes, fac, go, settlements]);

  // Compute procurement PO total value
  const poTotalValue = useMemo(() =>
    allOrders.reduce((sum, o) => sum + o.lines.reduce((ls, l) => ls + (l.unitPriceMinor * l.quantityOrdered), 0), 0),
  [allOrders]);

  const openPOs = allOrders.filter(o => o.status === 'confirmed' || o.status === 'draft').length;

  return (
    <div className="page fin-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Finance</h1>
          <p className="page__subtitle">Revenue cycle, invoices, payments, settlements, reconciliation</p>
        </div>
        <div className="fin-actions">
          <Button variant="ghost" onClick={() => { settlements.refresh(); orders.refresh(); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="fin-census">
        <div className="fin-census-card fin-census-card--invoices">
          <span className="fin-census-value">—</span>
          <span className="fin-census-label">Total Invoices</span>
        </div>
        <div className="fin-census-card fin-census-card--outstanding">
          <span className="fin-census-value" style={{ color: '#f59e0b' }}>—</span>
          <span className="fin-census-label">Outstanding</span>
        </div>
        <div className="fin-census-card fin-census-card--collected">
          <span className="fin-census-value" style={{ color: '#10b981' }}>—</span>
          <span className="fin-census-label">Collected Today</span>
        </div>
        <div className="fin-census-card fin-census-card--settlements">
          <span className="fin-census-value">{allSettlements.length}</span>
          <span className="fin-census-label">Settlements</span>
        </div>
        <div className="fin-census-card fin-census-card--procurement">
          <span className="fin-census-value">{openPOs}</span>
          <span className="fin-census-label">Open POs</span>
        </div>
        <div className="fin-census-card fin-census-card--spend">
          <span className="fin-census-value">{formatMoney(poTotalValue)}</span>
          <span className="fin-census-label">PO Value</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="fin-tabs">
        {(['invoices', 'payments', 'aging', 'settlements', 'procurement'] as const).map(t => (
          <button key={t} className={`fin-tab ${activeTab === t ? 'fin-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'invoices' ? 'Invoices' : t === 'payments' ? 'Payments' : t === 'aging' ? 'Aging' : t === 'settlements' ? 'Settlements' : 'Procurement Spend'}
          </button>
        ))}
      </div>

      {/* ── Invoices Tab ──────────────────────────────────── */}
      {activeTab === 'invoices' && (
        <Card className="fin-section-card">
          <div className="fin-section-header">
            <h3>Invoices</h3>
            <div className="fin-section-actions">
              <div className="fin-filter-pills">
                {['all', 'issued', 'partially_paid', 'paid', 'voided'].map(f => (
                  <button key={f} className={`fin-pill ${invFilter === f ? 'fin-pill--active' : ''}`}
                    onClick={() => setInvFilter(f)}>
                    {f === 'all' ? 'All' : (INV_STATUS[f]?.label ?? f)}
                  </button>
                ))}
              </div>
            </div>
          </div>
          <EmptyState title="Invoice workspace" body="Patient invoices appear here. Create invoices from encounters via the Billing module." />
        </Card>
      )}

      {/* ── Payments Tab ──────────────────────────────────── */}
      {activeTab === 'payments' && (
        <Card className="fin-section-card">
          <div className="fin-section-header">
            <h3>Payment Processing</h3>
          </div>
          <EmptyState title="Payment processing" body="Process payments against invoices. Navigate to a patient invoice to capture payment." />
        </Card>
      )}

      {/* ── Aging Tab ─────────────────────────────────────── */}
      {activeTab === 'aging' && (
        <Card className="fin-section-card">
          <div className="fin-section-header">
            <h3>Accounts Receivable — Aging</h3>
          </div>
          <EmptyState title="Aging report" body="Outstanding patient balances and aging information appear here." />
        </Card>
      )}

      {/* ── Settlements Tab ────────────────────────────────── */}
      {activeTab === 'settlements' && (
        <Card className="fin-section-card">
          <div className="fin-section-header">
            <h3>Daily Cash Settlements</h3>
            <Button variant="primary" size="sm" onClick={() => { setSettlementDate(new Date().toISOString().slice(0, 10)); setActualMinor(''); setSettlementNotes(''); setDlg('settlement'); }}>
              Reconcile Today
            </Button>
          </div>
          {allSettlements.length === 0 ? (
            <EmptyState title="No settlements" body="Daily cashier settlements appear here after reconciliation." />
          ) : (
            <div className="fin-table">
              <div className="fin-table-header">
                <span>Date</span>
                <span>Cashier</span>
                <span className="fin-num">Expected</span>
                <span className="fin-num">Actual</span>
                <span className="fin-num">Variance</span>
                <span>Status</span>
              </div>
              {allSettlements.map(s => (
                <div key={s.id} className="fin-table-row">
                  <span>{s.settlementDate}</span>
                  <span className="fin-mono">{s.cashierId.slice(0, 8)}...</span>
                  <span className="fin-num">{formatMoney(s.expectedMinor)}</span>
                  <span className="fin-num">{formatMoney(s.actualMinor)}</span>
                  <span className={`fin-num ${s.varianceMinor !== 0 ? 'fin-num--negative' : ''}`}>
                    {formatMoney(s.varianceMinor)}
                  </span>
                  <StatusBadge status={s.status} config={SETTLEMENT_STATUS} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Procurement Spend Tab ──────────────────────────── */}
      {activeTab === 'procurement' && (
        <Card className="fin-section-card">
          <div className="fin-section-header">
            <h3>Procurement Spend</h3>
          </div>
          {allOrders.length === 0 ? (
            <EmptyState title="No purchase orders" body="Procurement spend data appears here when purchase orders exist." />
          ) : (
            <div className="fin-table">
              <div className="fin-table-header">
                <span>PO Number</span>
                <span>Status</span>
                <span className="fin-num">Total Value</span>
                <span>Lines</span>
              </div>
              {allOrders.map(o => {
                const totalValue = o.lines.reduce((sum, l) => sum + (l.unitPriceMinor * l.quantityOrdered), 0);
                return (
                  <div key={o.id} className="fin-table-row">
                    <span className="fin-mono">{o.poNumber}</span>
                    <StatusBadge status={o.status} config={INV_STATUS} />
                    <span className="fin-num">{formatMoney(totalValue)}</span>
                    <span>{o.lines.length} items</span>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* Settlement Dialog */}
      {dlg === 'settlement' && (
        <Dialog open onClose={() => setDlg(null)} title="Reconcile Daily Settlement" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleReconcile} loading={busy} disabled={!actualMinor}>Reconcile</Button>
          </>
        }>
          <form onSubmit={handleReconcile} className="fin-form">
            <Input label="Settlement Date" type="date" value={settlementDate} onChange={e => setSettlementDate(e.target.value)} />
            <Input label="Actual Amount (minor units)" type="number" value={actualMinor} onChange={e => setActualMinor(e.target.value)} placeholder="e.g. 150000" />
            <Input label="Notes" value={settlementNotes} onChange={e => setSettlementNotes(e.target.value)} placeholder="Optional reconciliation notes" />
            <Alert tone="info">Enter the actual cash counted. Variance will be calculated automatically.</Alert>
          </form>
        </Dialog>
      )}
    </div>
  );
}
