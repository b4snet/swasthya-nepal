import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { procurementApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Spinner, money } from '../components/ui';
import { ApiError } from '../api/client';

export function ProcurementPage() {
  const { selectedFacilityId, organizationId } = useTenant();
  const fac = selectedFacilityId;
  const org = organizationId;

  const [tab, setTab] = useState<'vendors' | 'requests' | 'orders'>('vendors');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const vendors = useFetch(() => org ? procurementApi.vendors(org, fac) : Promise.resolve([]), [org, fac]);
  const requests = useFetch(() => org ? procurementApi.requests(org, fac) : Promise.resolve([]), [org, fac]);
  const orders = useFetch(() => org ? procurementApi.orders(org, fac) : Promise.resolve([]), [org, fac]);

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Procurement</h1>
          <span className="page__sub">Vendors, purchase requests, and orders</span>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <div className="tabs" role="tablist">
        {(['vendors', 'requests', 'orders'] as const).map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} className={`tabs__tab ${tab === t ? 'tabs__tab--active' : ''}`} onClick={() => setTab(t)}>
            {t === 'vendors' ? 'Vendors' : t === 'requests' ? 'Purchase Requests' : 'Purchase Orders'}
          </button>
        ))}
      </div>

      {tab === 'vendors' && <VendorTab vendors={vendors.data ?? []} loading={vendors.loading} error={vendors.error} onRefresh={() => void vendors.refresh()} org={org} fac={fac} setNotice={setNotice} />}
      {tab === 'requests' && <RequestTab requests={requests.data ?? []} loading={requests.loading} error={requests.error} onRefresh={() => void requests.refresh()} fac={fac} setNotice={setNotice} />}
      {tab === 'orders' && <OrderTab orders={orders.data ?? []} loading={orders.loading} error={orders.error} onRefresh={() => void orders.refresh()} fac={fac} setNotice={setNotice} />}
    </div>
  );
}

function VendorTab({ vendors, loading, error, onRefresh, org, fac, setNotice }: {
  vendors: any[]; loading: boolean; error: any; onRefresh: () => void; org: string | null; fac: string | null;
  setNotice: (n: any) => void;
}) {
  const [createOpen, setCreateOpen] = useState(false);
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);

  const handleCreate = async () => {
    if (!org || !code.trim() || !name.trim()) return;
    setBusy(true);
    try {
      await procurementApi.storeVendor(org, { code: code.trim(), name: name.trim() }, fac);
      setNotice({ tone: 'success', text: 'Vendor created.' });
      setCreateOpen(false);
      setCode('');
      setName('');
      onRefresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed.' });
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <Spinner />;
  if (error) return <ErrorState error={error} onRetry={onRefresh} />;

  return (
    <Card title="Vendors">
      <div className="row mb-4"><Button onClick={() => setCreateOpen(true)}>Add vendor</Button></div>
      {vendors.length === 0 ? (
        <EmptyState title="No vendors" body="Add vendors to start procurement." />
      ) : (
        <table className="data-table">
          <thead><tr><th>Code</th><th>Name</th><th>Status</th></tr></thead>
          <tbody>
            {vendors.map((v) => (
              <tr key={v.id}>
                <td className="mono">{v.code}</td>
                <td>{v.name}</td>
                <td><span className="status-chip status-chip--neutral">{v.status}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} title="Add vendor"
        footer={<><Button variant="ghost" onClick={() => setCreateOpen(false)}>Cancel</Button><Button onClick={() => void handleCreate()} loading={busy} disabled={!code.trim() || !name.trim()}>Create</Button></>}>
        <div className="stack">
          <Input label="Code" value={code} onChange={(e) => setCode(e.target.value)} />
          <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} />
        </div>
      </Dialog>
    </Card>
  );
}

function RequestTab({ requests, loading, error, onRefresh, fac, setNotice }: {
  requests: any[]; loading: boolean; error: any; onRefresh: () => void; fac: string | null;
  setNotice: (n: any) => void;
}) {
  const [busy, setBusy] = useState(false);

  const handleAction = async (id: string, action: 'submit' | 'approve' | 'reject') => {
    setBusy(true);
    try {
      if (action === 'submit') await procurementApi.submitRequest(id, fac);
      else if (action === 'approve') await procurementApi.approveRequest(id, fac);
      else if (action === 'reject') await procurementApi.rejectRequest(id, fac);
      setNotice({ tone: 'success', text: `Request ${action}ed.` });
      onRefresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed.' });
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <Spinner />;
  if (error) return <ErrorState error={error} onRetry={onRefresh} />;

  return (
    <Card title="Purchase Requests">
      {requests.length === 0 ? (
        <EmptyState title="No purchase requests" body="Create a request to start procurement." />
      ) : (
        <table className="data-table">
          <thead><tr><th>Number</th><th>Status</th><th className="num">Est. Total</th><th>Lines</th><th></th></tr></thead>
          <tbody>
            {requests.map((r) => (
              <tr key={r.id}>
                <td className="mono">{r.requestNumber}</td>
                <td><span className={`status-chip status-chip--${r.status === 'approved' ? 'success' : r.status === 'rejected' ? 'danger' : 'info'}`}>{r.status}</span></td>
                <td className="num">{money(r.estimatedTotalMinor)}</td>
                <td>{r.lines.length} items</td>
                <td>
                  {r.status === 'draft' && <Button size="sm" onClick={() => void handleAction(r.id, 'submit')} loading={busy}>Submit</Button>}
                  {r.status === 'submitted' && <>
                    <Button size="sm" onClick={() => void handleAction(r.id, 'approve')} loading={busy}>Approve</Button>
                    <Button size="sm" variant="ghost" onClick={() => void handleAction(r.id, 'reject')} loading={busy}>Reject</Button>
                  </>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  );
}

function OrderTab({ orders, loading, error, onRefresh, fac, setNotice }: {
  orders: any[]; loading: boolean; error: any; onRefresh: () => void; fac: string | null;
  setNotice: (n: any) => void;
}) {
  const [busy, setBusy] = useState(false);

  const handleAction = async (id: string, action: 'confirm' | 'close') => {
    setBusy(true);
    try {
      if (action === 'confirm') await procurementApi.confirmOrder(id, fac);
      else if (action === 'close') await procurementApi.closeOrder(id, fac);
      setNotice({ tone: 'success', text: `Order ${action}ed.` });
      onRefresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed.' });
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <Spinner />;
  if (error) return <ErrorState error={error} onRetry={onRefresh} />;

  return (
    <Card title="Purchase Orders">
      {orders.length === 0 ? (
        <EmptyState title="No purchase orders" body="Create orders from approved requests." />
      ) : (
        <table className="data-table">
          <thead><tr><th>PO Number</th><th>Status</th><th>Expected</th><th>Lines</th><th></th></tr></thead>
          <tbody>
            {orders.map((o) => (
              <tr key={o.id}>
                <td className="mono">{o.poNumber}</td>
                <td><span className={`status-chip status-chip--${o.status === 'closed' ? 'success' : 'info'}`}>{o.status}</span></td>
                <td>{o.expectedDelivery ?? '—'}</td>
                <td>{o.lines.length} items</td>
                <td>
                  {o.status === 'draft' && <Button size="sm" onClick={() => void handleAction(o.id, 'confirm')} loading={busy}>Confirm</Button>}
                  {o.status === 'confirmed' && <Button size="sm" onClick={() => void handleAction(o.id, 'close')} loading={busy}>Close</Button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  );
}
