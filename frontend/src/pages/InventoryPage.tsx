import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { inventoryApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Dialog, EmptyState, Input, Spinner } from '../components/ui';
import { ApiError } from '../api/client';

export function InventoryPage() {
  const { selectedFacilityId, organizationId } = useTenant();
  const fac = selectedFacilityId;
  const org = organizationId;

  const inventory = useFetch(() => inventoryApi.list(org ?? '', fac), [org, fac]);
  const reorderAlerts = useFetch(() => inventoryApi.reorderAlerts(org ?? '', fac), [org, fac]);

  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [selectedItem, setSelectedItem] = useState<string | null>(null);
  const [adjustOpen, setAdjustOpen] = useState(false);
  const [adjustDelta, setAdjustDelta] = useState('');
  const [adjustReason, setAdjustReason] = useState('');
  const [busy, setBusy] = useState(false);

  const batches = useFetch(
    () => (selectedItem ? inventoryApi.batches(selectedItem, fac) : Promise.resolve([])),
    [selectedItem, fac],
  );

  const handleAdjust = async () => {
    if (!selectedItem || !adjustDelta || !adjustReason) return;
    setBusy(true);
    try {
      await inventoryApi.adjust(selectedItem, { quantityDelta: parseInt(adjustDelta, 10), reason: adjustReason }, fac);
      setNotice({ tone: 'success', text: 'Stock adjusted.' });
      setAdjustOpen(false);
      setAdjustDelta('');
      setAdjustReason('');
      void inventory.refresh();
      void batches.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Adjustment failed.' });
    } finally {
      setBusy(false);
    }
  };

  const items = inventory.data ?? [];
  const alerts = reorderAlerts.data ?? [];

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Inventory</h1>
          <span className="page__sub">Stock levels, batches, and movements</span>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {alerts.length > 0 && (
        <Card title="Reorder alerts">
          <Alert tone="warning">{alerts.length} items are at or below reorder level</Alert>
          <table className="data-table">
            <thead>
              <tr><th>Medication</th><th className="num">On hand</th><th className="num">Reorder level</th></tr>
            </thead>
            <tbody>
              {alerts.map((item) => (
                <tr key={item.id}>
                  <td>{item.medication?.genericName ?? '—'}</td>
                  <td className="num">{item.quantityOnHand}</td>
                  <td className="num">{item.reorderLevel}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <Card title="Stock levels">
        {items.length === 0 ? (
          <EmptyState title="No inventory items" body="Add medications to the formulary first." />
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Medication</th>
                <th className="num">On hand</th>
                <th className="num">Reorder level</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{item.medication?.genericName ?? '—'} <span className="muted small">{item.medication?.strength}</span></td>
                  <td className="num">{item.quantityOnHand}</td>
                  <td className="num">{item.reorderLevel}</td>
                  <td>
                    <Button size="sm" onClick={() => { setSelectedItem(item.id); }}>Batches</Button>
                    <Button size="sm" variant="ghost" onClick={() => { setSelectedItem(item.id); setAdjustOpen(true); }}>Adjust</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      {selectedItem && (
        <Card title="Batches">
          {batches.loading ? <Spinner /> : (
            (batches.data ?? []).length === 0 ? (
              <p className="muted">No batches recorded.</p>
            ) : (
              <table className="data-table">
                <thead>
                  <tr><th>Batch #</th><th>Expiry</th><th className="num">Received</th><th className="num">Remaining</th><th>Status</th></tr>
                </thead>
                <tbody>
                  {(batches.data ?? []).map((b) => (
                    <tr key={b.id}>
                      <td className="mono">{b.batchNumber}</td>
                      <td>{b.expiryDate ?? '—'}</td>
                      <td className="num">{b.quantityReceived}</td>
                      <td className="num">{b.quantityRemaining}</td>
                      <td><span className={`status-chip status-chip--${b.expiryStatus === 'expired' ? 'danger' : b.expiryStatus === 'expiring_soon' ? 'info' : 'neutral'}`}>{b.expiryStatus}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )
          )}
          <Button variant="ghost" onClick={() => setSelectedItem(null)} className="mt-2">Close batches</Button>
        </Card>
      )}

      <Dialog
        open={adjustOpen}
        onClose={() => setAdjustOpen(false)}
        title="Adjust stock"
        footer={
          <>
            <Button variant="ghost" onClick={() => setAdjustOpen(false)}>Cancel</Button>
            <Button onClick={() => void handleAdjust()} loading={busy} disabled={!adjustDelta || !adjustReason}>Confirm</Button>
          </>
        }
      >
        <div className="stack">
          <Input label="Quantity delta (positive = add, negative = subtract)" type="number" value={adjustDelta} onChange={(e) => setAdjustDelta(e.target.value)} />
          <Input label="Reason" value={adjustReason} onChange={(e) => setAdjustReason(e.target.value)} placeholder="Why is this adjustment needed?" />
        </div>
      </Dialog>
    </div>
  );
}
