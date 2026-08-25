import { useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { assetApi } from '../api/endpoints';
import { Card, EmptyState } from '../components/ui';

type Tab = 'assets' | 'maintenance' | 'workorders';

const LS: Record<string, { color: string; bg: string }> = {
  procured: { color: '#f59e0b', bg: '#fef3c7' },
  deployed: { color: '#10b981', bg: '#ecfdf5' },
  under_repair: { color: '#f59e0b', bg: '#fef3c7' },
  retired: { color: '#9ca3af', bg: '#f9fafb' },
  open: { color: '#f59e0b', bg: '#fef3c7' },
  closed: { color: '#10b981', bg: '#ecfdf5' },
};

function Badge({ s }: { s: string }) {
  const c = LS[s] ?? { color: '#6b7280', bg: '#f3f4f6' };
  return (<span style={{ color: c.color, backgroundColor: c.bg, padding: '2px 8px', borderRadius: 12, fontSize: 12, fontWeight: 500 }}>{s.replace(/_/g, ' ')}</span>);
}

export function AssetPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [tab, setTab] = useState<Tab>('assets');
  const tabs: Tab[] = ['assets', 'maintenance', 'workorders'];
  const tc = (t: Tab) => 'tabs__tab' + (tab === t ? ' tabs__tab--active' : '');
  return (
    <div className="page">
      <header className="page__head"><div>
        <h1 className="page__title">Asset Management</h1>
        <p className="page__subtitle">Register, lifecycle, maintenance, work orders</p>
      </div></header>
      <div className="tabs" role="tablist">
        {tabs.map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} className={tc(t)} onClick={() => setTab(t)}>
            {t.charAt(0).toUpperCase() + t.slice(1)}
          </button>))}
      </div>
      {tab === 'assets' && <AssetsTab fac={fac} />}
      {tab === 'maintenance' && <MaintenanceTab fac={fac} />}
      {tab === 'workorders' && <WorkOrdersTab fac={fac} />}
    </div>);
}

function AssetsTab({ fac }: { fac: string | null }) {
  const assets = useFetch(() => assetApi.list(fac), [fac]);
  const all = useMemo(() => (assets.data ?? []) as any[], [assets.data]);
  const deployed = all.filter((a: any) => a.lifecycleStatus === 'deployed').length;
  const procured = all.filter((a: any) => a.lifecycleStatus === 'procured').length;
  const retired = all.filter((a: any) => a.lifecycleStatus === 'retired').length;
  return (<>
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: 12, marginBottom: 16 }}>
      <Card><div style={{ textAlign: 'center' }}><div style={{ fontSize: 28, fontWeight: 700 }}>{all.length}</div><div style={{ fontSize: 13, color: '#64748b' }}>Total</div></div></Card>
      <Card><div style={{ textAlign: 'center' }}><div style={{ fontSize: 28, fontWeight: 700, color: '#10b981' }}>{deployed}</div><div style={{ fontSize: 13, color: '#64748b' }}>Deployed</div></div></Card>
      <Card><div style={{ textAlign: 'center' }}><div style={{ fontSize: 28, fontWeight: 700, color: '#f59e0b' }}>{procured}</div><div style={{ fontSize: 13, color: '#64748b' }}>Procured</div></div></Card>
      <Card><div style={{ textAlign: 'center' }}><div style={{ fontSize: 28, fontWeight: 700, color: '#9ca3af' }}>{retired}</div><div style={{ fontSize: 13, color: '#64748b' }}>Retired</div></div></Card>
    </div>
    <Card title="Asset Register">
      {all.length === 0 ? <EmptyState title="No assets" body="Register assets to begin tracking." /> : (
        <table className="data-table"><thead><tr><th>Name</th><th>Serial</th><th>Category</th><th>Lifecycle</th><th>Status</th></tr></thead>
          <tbody>{all.map((a: any) => (<tr key={a.id}><td className="mono">{a.name}</td><td>{a.serialNumber ?? '---'}</td><td>{a.categoryId}</td><td><Badge s={a.lifecycleStatus} /></td><td><Badge s={a.status} /></td></tr>))}</tbody></table>)}
    </Card></>);
}

function MaintenanceTab({ fac }: { fac: string | null }) {
  const schedules = useFetch(() => assetApi.maintenanceSchedules(fac), [fac]);
  const all = useMemo(() => (schedules.data ?? []) as any[], [schedules.data]);
  return (<Card title="Maintenance Schedules">
    {all.length === 0 ? <EmptyState title="No schedules" body="Create maintenance schedules for assets." /> : (
      <table className="data-table"><thead><tr><th>Asset</th><th>Type</th><th>Freq (days)</th><th>Next Due</th><th>Status</th></tr></thead>
        <tbody>{all.map((s: any) => (<tr key={s.id}><td className="mono">{s.assetId}</td><td>{s.scheduleType}</td><td>{s.frequencyDays ?? '---'}</td><td>{s.nextDueDate}</td><td><Badge s={s.status} /></td></tr>))}</tbody></table>)}
  </Card>);
}

function WorkOrdersTab({ fac }: { fac: string | null }) {
  const orders = useFetch(() => assetApi.workOrders(fac), [fac]);
  const all = useMemo(() => (orders.data ?? []) as any[], [orders.data]);
  const open = all.filter((w: any) => w.status === 'open').length;
  return (<>
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: 12, marginBottom: 16 }}>
      <Card><div style={{ textAlign: 'center' }}><div style={{ fontSize: 28, fontWeight: 700 }}>{all.length}</div><div style={{ fontSize: 13, color: '#64748b' }}>Total</div></div></Card>
      <Card><div style={{ textAlign: 'center' }}><div style={{ fontSize: 28, fontWeight: 700, color: '#f59e0b' }}>{open}</div><div style={{ fontSize: 13, color: '#64748b' }}>Open</div></div></Card>
    </div>
    <Card title="Work Orders">
      {all.length === 0 ? <EmptyState title="No work orders" body="Open work orders for maintenance." /> : (
        <table className="data-table"><thead><tr><th>Number</th><th>Asset</th><th>Status</th><th>Opened</th><th>Completed</th></tr></thead>
          <tbody>{all.map((w: any) => (<tr key={w.id}><td className="mono">{w.workOrderNumber}</td><td>{w.assetId}</td><td><Badge s={w.status} /></td><td>{w.openedAt ? new Date(w.openedAt).toLocaleDateString() : '---'}</td><td>{w.completedAt ? new Date(w.completedAt).toLocaleDateString() : '---'}</td></tr>))}</tbody></table>)}
    </Card></>);
}
