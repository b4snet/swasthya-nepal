import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { inventoryApi, procurementApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/inventory-cmd.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface InventoryItem {
  id: string;
  medicationId: string;
  quantityOnHand: number;
  reorderLevel: number;
  medication?: { id: string; genericName: string; strength: string; form: string };
}

interface StockBatch {
  id: string;
  batchNumber: string;
  expiryDate: string | null;
  quantityReceived: number;
  quantityRemaining: number;
  expiryStatus: string;
}

interface Vendor {
  id: string;
  code: string;
  name: string;
  status: string;
}

interface PurchaseRequest {
  id: string;
  requestNumber: string;
  status: string;
  estimatedTotalMinor: number;
  lines: Array<{ id: string; medicationId: string; quantity: number; estimatedUnitPriceMinor: number }>;
}

interface PurchaseOrder {
  id: string;
  poNumber: string;
  status: string;
  expectedDelivery: string | null;
  lines: Array<{ id: string; medicationId: string; quantityOrdered: number; receivedQuantity: number; unitPriceMinor: number }>;
}

/* ── Constants ───────────────────────────────────────────────────── */

const INV_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  draft: { label: 'Draft', color: '#6b7280', bg: '#f3f4f6' },
  submitted: { label: 'Submitted', color: '#f59e0b', bg: '#fef3c7' },
  approved: { label: 'Approved', color: '#10b981', bg: '#ecfdf5' },
  rejected: { label: 'Rejected', color: '#ef4444', bg: '#fee2e2' },
  confirmed: { label: 'Confirmed', color: '#3b82f6', bg: '#dbeafe' },
  partially_received: { label: 'Partial', color: '#f59e0b', bg: '#fef3c7' },
  received: { label: 'Received', color: '#10b981', bg: '#ecfdf5' },
  closed: { label: 'Closed', color: '#10b981', bg: '#ecfdf5' },
  cancelled: { label: 'Cancelled', color: '#6b7280', bg: '#f3f4f6' },
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
};

const EXPIRY_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  valid: { label: 'Valid', color: '#10b981', bg: '#ecfdf5' },
  expiring_soon: { label: 'Expiring Soon', color: '#f59e0b', bg: '#fef3c7' },
  expired: { label: 'Expired', color: '#ef4444', bg: '#fee2e2' },
};

function StatusBadge({ status, config }: { status: string; config: Record<string, { label: string; color: string; bg: string }> }) {
  const c = config[status] ?? { label: status.replace(/_/g, ' '), color: '#6b7280', bg: '#f3f4f6' };
  return <span className="inv-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>;
}

function formatMoney(cents: number): string {
  return `$${(cents / 100).toFixed(2)}`;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function InventoryPage() {
  const { selectedFacilityId: fac, organizationId: org } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'stock' | 'batches' | 'procurement' | 'transfers' | 'adjustments'>('stock');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Selection state
  const [selectedItemId, setSelectedItemId] = useState<string | null>(null);
  const [selectedItemIdBatches, setSelectedItemIdBatches] = useState<string | null>(null);

  // Adjustment form
  const [adjDelta, setAdjDelta] = useState('');
  const [adjReason, setAdjReason] = useState('');

  // Transfer form
  const [transferFrom, setTransferFrom] = useState('');
  const [transferTo, setTransferTo] = useState('');
  const [transferQty, setTransferQty] = useState('');
  const [transferReason, setTransferReason] = useState('');

  // Data fetching
  const inventory = useFetch(
    () => org ? inventoryApi.list(org, fac) : Promise.resolve([]),
    [org, fac],
  );

  const reorderAlerts = useFetch(
    () => org ? inventoryApi.reorderAlerts(org, fac) : Promise.resolve([]),
    [org, fac],
  );

  const vendors = useFetch(
    () => org ? procurementApi.vendors(org, fac) : Promise.resolve([]),
    [org, fac],
  );

  const requests = useFetch(
    () => org ? procurementApi.requests(org, fac) : Promise.resolve([]),
    [org, fac],
  );

  const orders = useFetch(
    () => org ? procurementApi.orders(org, fac) : Promise.resolve([]),
    [org, fac],
  );

  const batches = useFetch(
    () => selectedItemIdBatches ? inventoryApi.batches(selectedItemIdBatches, fac) : Promise.resolve([]),
    [selectedItemIdBatches, fac],
  );

  const allInventory = useMemo(() => (inventory.data ?? []) as InventoryItem[], [inventory.data]);
  const allAlerts = useMemo(() => (reorderAlerts.data ?? []) as InventoryItem[], [reorderAlerts.data]);
  const allVendors = useMemo(() => (vendors.data ?? []) as Vendor[], [vendors.data]);
  const allRequests = useMemo(() => (requests.data ?? []) as PurchaseRequest[], [requests.data]);
  const allOrders = useMemo(() => (orders.data ?? []) as PurchaseOrder[], [orders.data]);
  const allBatches = useMemo(() => (batches.data ?? []) as StockBatch[], [batches.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Actions
  const handleAdjust = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedItemId || !adjDelta || !adjReason) return;
    await go(() => inventoryApi.adjust(selectedItemId, { quantityDelta: parseInt(adjDelta), reason: adjReason }, fac));
    setDlg(null); setAdjDelta(''); setAdjReason('');
    inventory.refresh();
    if (selectedItemIdBatches) batches.refresh();
  }, [selectedItemId, adjDelta, adjReason, fac, go, inventory, batches, selectedItemIdBatches]);

  const handleTransfer = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!transferFrom || !transferTo || !transferQty || !transferReason) return;
    await go(() => inventoryApi.transfer({
      fromInventoryItemId: transferFrom,
      toInventoryItemId: transferTo,
      quantity: parseInt(transferQty),
      reason: transferReason,
    }, fac));
    setDlg(null); setTransferFrom(''); setTransferTo(''); setTransferQty(''); setTransferReason('');
    inventory.refresh();
  }, [transferFrom, transferTo, transferQty, transferReason, fac, go, inventory]);

  const handleApproveRequest = useCallback(async (id: string) => {
    await go(() => procurementApi.approveRequest(id, fac));
    requests.refresh();
  }, [fac, go, requests]);

  const handleRejectRequest = useCallback(async (id: string) => {
    await go(() => procurementApi.rejectRequest(id, fac));
    requests.refresh();
  }, [fac, go, requests]);

  const handleSubmitRequest = useCallback(async (id: string) => {
    await go(() => procurementApi.submitRequest(id, fac));
    requests.refresh();
  }, [fac, go, requests]);

  const handleConfirmOrder = useCallback(async (id: string) => {
    await go(() => procurementApi.confirmOrder(id, fac));
    orders.refresh();
  }, [fac, go, orders]);

  const handleCloseOrder = useCallback(async (id: string) => {
    await go(() => procurementApi.closeOrder(id, fac));
    orders.refresh();
  }, [fac, go, orders]);

  const handleReceiveGoods = useCallback(async (orderId: string, e: React.FormEvent) => {
    e.preventDefault();
    const fd = e.currentTarget as HTMLFormElement;
    const data = new FormData(fd);
    const order = allOrders.find(o => o.id === orderId);
    if (!order) return;
    const lines = order.lines.map(l => ({
      purchaseOrderLineId: l.id,
      quantityReceived: parseInt(data.get(`qty_${l.id}`) as string) || 0,
    }));
    await go(() => procurementApi.receiveGoods(orderId, { lines }, fac));
    setDlg(null);
    orders.refresh();
    inventory.refresh();
  }, [allOrders, fac, go, orders, inventory]);

  // Census
  const totalStock = allInventory.reduce((sum, i) => sum + i.quantityOnHand, 0);
  const lowStockCount = allAlerts.length;
  const pendingRequests = allRequests.filter(r => r.status === 'submitted').length;
  const pendingOrders = allOrders.filter(o => o.status === 'confirmed').length;
  const totalVendors = allVendors.length;

  return (
    <div className="page inv-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Supply Chain</h1>
          <p className="page__subtitle">Inventory, procurement, stock management, vendor operations</p>
        </div>
        <div className="inv-actions">
          <Button variant="ghost" onClick={() => { inventory.refresh(); reorderAlerts.refresh(); vendors.refresh(); requests.refresh(); orders.refresh(); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="inv-census">
        <div className="inv-census-card inv-census-card--items">
          <span className="inv-census-value">{allInventory.length}</span>
          <span className="inv-census-label">Inventory Items</span>
        </div>
        <div className="inv-census-card inv-census-card--stock">
          <span className="inv-census-value">{totalStock}</span>
          <span className="inv-census-label">Total Units on Hand</span>
        </div>
        <div className="inv-census-card inv-census-card--alerts">
          <span className="inv-census-value" style={{ color: lowStockCount > 0 ? '#f59e0b' : undefined }}>{lowStockCount}</span>
          <span className="inv-census-label">Low Stock Alerts</span>
        </div>
        <div className="inv-census-card inv-census-card--requests">
          <span className="inv-census-value">{pendingRequests}</span>
          <span className="inv-census-label">Pending Requests</span>
        </div>
        <div className="inv-census-card inv-census-card--orders">
          <span className="inv-census-value">{pendingOrders}</span>
          <span className="inv-census-label">Open POs</span>
        </div>
        <div className="inv-census-card inv-census-card--vendors">
          <span className="inv-census-value">{totalVendors}</span>
          <span className="inv-census-label">Active Vendors</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="inv-tabs">
        {(['stock', 'batches', 'procurement', 'transfers', 'adjustments'] as const).map(t => (
          <button key={t} className={`inv-tab ${activeTab === t ? 'inv-tab--active' : ''}`}
            onClick={() => { setActiveTab(t); setSelectedItemId(null); setSelectedItemIdBatches(null); }}>
            {t === 'stock' ? 'Stock Levels' : t === 'batches' ? 'Batches & Expiry' : t === 'procurement' ? 'Procurement' : t === 'transfers' ? 'Transfers' : 'Adjustments'}
          </button>
        ))}
      </div>

      {/* ── Stock Levels Tab ───────────────────────────────── */}
      {activeTab === 'stock' && (
        <Card className="inv-section-card">
          <div className="inv-section-header">
            <h3>Inventory Stock Levels</h3>
            <div className="inv-section-actions">
              <Button variant="ghost" size="sm" onClick={() => inventory.refresh()}>Refresh</Button>
            </div>
          </div>
          {allInventory.length === 0 ? (
            <EmptyState title="No inventory items" body="Inventory items are created when medications are added to the formulary." />
          ) : (
            <div className="inv-stock-table">
              <div className="inv-stock-header">
                <span>Medication</span>
                <span className="inv-num">On Hand</span>
                <span className="inv-num">Reorder Level</span>
                <span>Status</span>
                <span>Actions</span>
              </div>
              {allInventory.map(item => {
                const med = item.medication;
                const isLow = item.quantityOnHand <= item.reorderLevel;
                return (
                  <div key={item.id} className={`inv-stock-row ${isLow ? 'inv-stock-row--low' : ''}`}>
                    <span className="inv-stock-med">
                      <span className="inv-stock-med-name">{med?.genericName ?? 'Unknown'}</span>
                      <span className="inv-stock-med-detail">{med?.strength} {med?.form}</span>
                    </span>
                    <span className="inv-num">{item.quantityOnHand}</span>
                    <span className="inv-num">{item.reorderLevel}</span>
                    <span>
                      {isLow ? (
                        <StatusBadge status="submitted" config={INV_STATUS} />
                      ) : (
                        <StatusBadge status="active" config={INV_STATUS} />
                      )}
                    </span>
                    <span className="inv-stock-actions">
                      <Button variant="ghost" size="sm" onClick={() => { setSelectedItemIdBatches(item.id); setActiveTab('batches'); }}>Batches</Button>
                      <Button variant="ghost" size="sm" onClick={() => { setSelectedItemId(item.id); setAdjDelta(''); setAdjReason(''); setDlg('adjust'); }}>Adjust</Button>
                    </span>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* ── Batches Tab ────────────────────────────────────── */}
      {activeTab === 'batches' && (
        <Card className="inv-section-card">
          <div className="inv-section-header">
            <h3>Batches & Expiry</h3>
            <div className="inv-section-actions">
              {allInventory.length > 0 && (
                <select className="inv-select" value={selectedItemIdBatches ?? ''} onChange={e => setSelectedItemIdBatches(e.target.value)}>
                  <option value="">Select item...</option>
                  {allInventory.map(item => (
                    <option key={item.id} value={item.id}>{item.medication?.genericName ?? item.id}</option>
                  ))}
                </select>
              )}
            </div>
          </div>
          {!selectedItemIdBatches ? (
            <EmptyState title="Select an item" body="Select an inventory item to view its batches and expiry information." />
          ) : allBatches.length === 0 ? (
            <EmptyState title="No batches" body="No batch records found for this item." />
          ) : (
            <div className="inv-stock-table">
              <div className="inv-stock-header">
                <span>Batch #</span>
                <span>Expiry</span>
                <span className="inv-num">Received</span>
                <span className="inv-num">Remaining</span>
                <span>Status</span>
              </div>
              {allBatches.map(b => (
                <div key={b.id} className="inv-stock-row">
                  <span className="inv-mono">{b.batchNumber}</span>
                  <span>{b.expiryDate ?? '—'}</span>
                  <span className="inv-num">{b.quantityReceived}</span>
                  <span className="inv-num">{b.quantityRemaining}</span>
                  <StatusBadge status={b.expiryStatus} config={EXPIRY_STATUS} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Procurement Tab ────────────────────────────────── */}
      {activeTab === 'procurement' && (
        <Card className="inv-section-card">
          <div className="inv-section-header">
            <h3>Procurement</h3>
          </div>

          {/* Vendors */}
          <div className="inv-proc-section">
            <h4>Vendors ({allVendors.length})</h4>
            {allVendors.length === 0 ? (
              <EmptyState title="No vendors" body="Add vendors to begin procurement." />
            ) : (
              <div className="inv-stock-table">
                <div className="inv-stock-header">
                  <span>Code</span>
                  <span>Name</span>
                  <span>Status</span>
                </div>
                {allVendors.map(v => (
                  <div key={v.id} className="inv-stock-row">
                    <span className="inv-mono">{v.code}</span>
                    <span>{v.name}</span>
                    <StatusBadge status={v.status} config={INV_STATUS} />
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Purchase Requests */}
          <div className="inv-proc-section">
            <h4>Purchase Requests ({allRequests.length})</h4>
            {allRequests.length === 0 ? (
              <EmptyState title="No purchase requests" body="Create purchase requests from department requisitions." />
            ) : (
              <div className="inv-stock-table">
                <div className="inv-stock-header">
                  <span>Request #</span>
                  <span>Status</span>
                  <span className="inv-num">Est. Total</span>
                  <span>Lines</span>
                  <span>Actions</span>
                </div>
                {allRequests.map(r => (
                  <div key={r.id} className="inv-stock-row">
                    <span className="inv-mono">{r.requestNumber}</span>
                    <StatusBadge status={r.status} config={INV_STATUS} />
                    <span className="inv-num">{formatMoney(r.estimatedTotalMinor)}</span>
                    <span>{r.lines.length} items</span>
                    <span className="inv-stock-actions">
                      {r.status === 'draft' && (
                        <Button variant="ghost" size="sm" onClick={() => void handleSubmitRequest(r.id)} loading={busy}>Submit</Button>
                      )}
                      {r.status === 'submitted' && (
                        <>
                          <Button variant="primary" size="sm" onClick={() => void handleApproveRequest(r.id)} loading={busy}>Approve</Button>
                          <Button variant="ghost" size="sm" onClick={() => void handleRejectRequest(r.id)} loading={busy}>Reject</Button>
                        </>
                      )}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Purchase Orders */}
          <div className="inv-proc-section">
            <h4>Purchase Orders ({allOrders.length})</h4>
            {allOrders.length === 0 ? (
              <EmptyState title="No purchase orders" body="Create POs from approved purchase requests." />
            ) : (
              <div className="inv-stock-table">
                <div className="inv-stock-header">
                  <span>PO Number</span>
                  <span>Status</span>
                  <span>Expected</span>
                  <span>Lines</span>
                  <span>Actions</span>
                </div>
                {allOrders.map(o => (
                  <div key={o.id} className="inv-stock-row">
                    <span className="inv-mono">{o.poNumber}</span>
                    <StatusBadge status={o.status} config={INV_STATUS} />
                    <span>{o.expectedDelivery ?? '—'}</span>
                    <span>{o.lines.length} items</span>
                    <span className="inv-stock-actions">
                      {o.status === 'draft' && (
                        <Button variant="primary" size="sm" onClick={() => void handleConfirmOrder(o.id)} loading={busy}>Confirm</Button>
                      )}
                      {o.status === 'confirmed' && (
                        <>
                          <Button variant="primary" size="sm" onClick={() => { setSelectedItemId(o.id); setDlg('receive'); }}>Receive</Button>
                          <Button variant="ghost" size="sm" onClick={() => void handleCloseOrder(o.id)} loading={busy}>Close</Button>
                        </>
                      )}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </Card>
      )}

      {/* ── Transfers Tab ──────────────────────────────────── */}
      {activeTab === 'transfers' && (
        <Card className="inv-section-card">
          <div className="inv-section-header">
            <h3>Stock Transfers</h3>
            <Button variant="primary" size="sm" onClick={() => { setTransferFrom(''); setTransferTo(''); setTransferQty(''); setTransferReason(''); setDlg('transfer'); }}>
              New Transfer
            </Button>
          </div>
          <EmptyState title="Stock transfers" body="Transfer stock between inventory locations. Use the 'New Transfer' button to initiate." />
        </Card>
      )}

      {/* ── Adjustments Tab ────────────────────────────────── */}
      {activeTab === 'adjustments' && (
        <Card className="inv-section-card">
          <div className="inv-section-header">
            <h3>Stock Adjustments</h3>
          </div>
          <EmptyState title="Stock adjustments" body="Select an item from Stock Levels to perform an adjustment with reason tracking." />
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* Adjust Dialog */}
      {dlg === 'adjust' && (
        <Dialog open onClose={() => setDlg(null)} title="Adjust Stock" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleAdjust} loading={busy} disabled={!adjDelta || !adjReason}>Confirm Adjustment</Button>
          </>
        }>
          <form onSubmit={handleAdjust} className="inv-form">
            <Input label="Quantity Delta (positive = add, negative = subtract)" type="number" value={adjDelta} onChange={e => setAdjDelta(e.target.value)} placeholder="e.g. 50 or -10" />
            <Input label="Reason" value={adjReason} onChange={e => setAdjReason(e.target.value)} placeholder="Why is this adjustment needed?" />
            <Alert tone="warning">Stock adjustments are auditable. Every change must have a documented reason.</Alert>
          </form>
        </Dialog>
      )}

      {/* Transfer Dialog */}
      {dlg === 'transfer' && (
        <Dialog open onClose={() => setDlg(null)} title="Stock Transfer" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleTransfer} loading={busy} disabled={!transferFrom || !transferTo || !transferQty || !transferReason}>Transfer Stock</Button>
          </>
        }>
          <form onSubmit={handleTransfer} className="inv-form">
            <div className="inv-form-field">
              <label className="inv-label">From Item</label>
              <select className="inv-input" value={transferFrom} onChange={e => setTransferFrom(e.target.value)}>
                <option value="">Select source...</option>
                {allInventory.map(item => (
                  <option key={item.id} value={item.id}>{item.medication?.genericName ?? item.id} (qty: {item.quantityOnHand})</option>
                ))}
              </select>
            </div>
            <div className="inv-form-field">
              <label className="inv-label">To Item</label>
              <select className="inv-input" value={transferTo} onChange={e => setTransferTo(e.target.value)}>
                <option value="">Select destination...</option>
                {allInventory.map(item => (
                  <option key={item.id} value={item.id}>{item.medication?.genericName ?? item.id} (qty: {item.quantityOnHand})</option>
                ))}
              </select>
            </div>
            <Input label="Quantity" type="number" value={transferQty} onChange={e => setTransferQty(e.target.value)} placeholder="Units to transfer" />
            <Input label="Reason" value={transferReason} onChange={e => setTransferReason(e.target.value)} placeholder="Transfer reason" />
            <Alert tone="info">Transfers move stock between locations. Both source and destination will be updated atomically.</Alert>
          </form>
        </Dialog>
      )}

      {/* Receive Goods Dialog */}
      {dlg === 'receive' && selectedItemId && (
        <Dialog open onClose={() => setDlg(null)} title="Receive Goods" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
          </>
        }>
          {(() => {
            const order = allOrders.find(o => o.id === selectedItemId);
            if (!order) return <p>Order not found</p>;
            return (
              <form onSubmit={(e) => void handleReceiveGoods(order.id, e)} className="inv-form">
                <div className="inv-receive-header">
                  <span>PO: {order.poNumber}</span>
                  <span>{order.lines.length} line items</span>
                </div>
                {order.lines.map(l => (
                  <div key={l.id} className="inv-receive-line">
                    <span>Line: {l.medicationId.slice(0, 8)}...</span>
                    <span>Ordered: {l.quantityOrdered}</span>
                    <span>Received so far: {l.receivedQuantity}</span>
                    <Input label="Qty to Receive" type="number" name={`qty_${l.id}`} placeholder="0" />
                  </div>
                ))}
                <Button type="submit" loading={busy}>Confirm Receipt</Button>
              </form>
            );
          })()}
        </Dialog>
      )}
    </div>
  );
}
