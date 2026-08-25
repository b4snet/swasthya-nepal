import { useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { hrApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, EmptyState } from '../components/ui';

type Tab = 'attendance' | 'scheduling' | 'leave' | 'payroll';

const SC: Record<string, { color: string; bg: string }> = {
  present: { color: '#10b981', bg: '#ecfdf5' },
  absent: { color: '#ef4444', bg: '#fee2e2' },
  late: { color: '#f59e0b', bg: '#fef3c7' },
  pending: { color: '#f59e0b', bg: '#fef3c7' },
  approved: { color: '#10b981', bg: '#ecfdf5' },
  rejected: { color: '#ef4444', bg: '#fee2e2' },
  scheduled: { color: '#3b82f6', bg: '#dbeafe' },
  confirmed: { color: '#10b981', bg: '#ecfdf5' },
};

function Badge({ s }: { s: string }) {
  const c = SC[s] ?? { color: '#6b7280', bg: '#f3f4f6' };
  return (<span style={{ color: c.color, backgroundColor: c.bg, padding: '2px 8px', borderRadius: 12, fontSize: 12, fontWeight: 500 }}>{s.replace(/_/g, ' ')}</span>);
}

export function HrPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [tab, setTab] = useState<Tab>('attendance');
  const [error, setError] = useState<string | null>(null);
  const tabs: Tab[] = ['attendance', 'scheduling', 'leave', 'payroll'];
  const tc = (t: Tab) => 'tabs__tab' + (tab === t ? ' tabs__tab--active' : '');
  return (
    <div className="page">
      <header className="page__head"><div>
        <h1 className="page__title">Staff & HR</h1>
        <p className="page__subtitle">Attendance, scheduling, leave, payroll</p>
      </div></header>
      {error && <Alert tone="danger">{error}</Alert>}
      <div className="tabs" role="tablist">
        {tabs.map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} className={tc(t)} onClick={() => setTab(t)}>
            {t.charAt(0).toUpperCase() + t.slice(1)}
          </button>))}
      </div>
      {tab === 'attendance' && <AttendanceTab fac={fac} />}
      {tab === 'scheduling' && <SchedulingTab fac={fac} />}
      {tab === 'leave' && <LeaveTab fac={fac} setError={setError} />}
      {tab === 'payroll' && <PayrollTab fac={fac} />}
    </div>);
}

function AttendanceTab({ fac }: { fac: string | null }) {
  const recs = useFetch(() => hrApi.attendance(fac), [fac]);
  const all = useMemo(() => (recs.data ?? []) as any[], [recs.data]);
  return (<>
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: 12, marginBottom: 16 }}>
      <Card><div style={{ textAlign: 'center' }}><div style={{ fontSize: 28, fontWeight: 700 }}>{all.length}</div><div style={{ fontSize: 13, color: '#64748b' }}>Total</div></div></Card>
    </div>
    <Card title="Attendance Records">
      {all.length === 0 ? <EmptyState title="No records" body="Staff attendance records." /> : (
        <table className="data-table"><thead><tr><th>Staff</th><th>Date</th><th>In</th><th>Out</th><th>Status</th></tr></thead>
          <tbody>{all.map((r: any) => (<tr key={r.id}><td className="mono">{r.staffId.slice(0,8)}...</td><td>{r.attendanceDate}</td><td>{r.clockInAt ? new Date(r.clockInAt).toLocaleTimeString() : '---'}</td><td>{r.clockOutAt ? new Date(r.clockOutAt).toLocaleTimeString() : '---'}</td><td><Badge s={r.status} /></td></tr>))}</tbody></table>)}
    </Card></>);
}

function SchedulingTab({ fac }: { fac: string | null }) {
  const shifts = useFetch(() => hrApi.shiftTemplates(fac), [fac]);
  const allS = useMemo(() => (shifts.data ?? []) as any[], [shifts.data]);
  return (<Card title="Shift Templates">
    {allS.length === 0 ? <EmptyState title="No shifts" body="Create shift templates." /> : (
      <table className="data-table"><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
        <tbody>{allS.map((s: any) => (<tr key={s.id}><td className="mono">{s.code}</td><td>{s.name}</td><td><Badge s={s.shiftType} /></td><td>{s.startsAt ?? '---'}</td><td>{s.endsAt ?? '---'}</td><td><Badge s={s.status} /></td></tr>))}</tbody></table>)}
  </Card>);
}

function LeaveTab({ fac, setError }: { fac: string | null; setError: (e: string | null) => void }) {
  const reqs = useFetch(() => hrApi.leaveRequests(fac), [fac]);
  const allR = useMemo(() => (reqs.data ?? []) as any[], [reqs.data]);
  const act = async (id: string, a: 'approve' | 'reject') => {
    try { if (a === 'approve') await hrApi.approveLeaveRequest(id, undefined, fac); else await hrApi.rejectLeaveRequest(id, undefined, fac); reqs.refresh(); } catch (e: any) { setError(e instanceof ApiError ? e.message : 'Failed'); }
  };
  return (<Card title="Leave Requests">
    {allR.length === 0 ? <EmptyState title="No requests" body="Leave requests." /> : (
      <table className="data-table"><thead><tr><th>Staff</th><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Status</th><th></th></tr></thead>
        <tbody>{allR.map((r: any) => (<tr key={r.id}><td className="mono">{r.staffId.slice(0,8)}...</td><td>{r.leaveTypeCode ?? '---'}</td><td>{r.startsOn}</td><td>{r.endsOn}</td><td>{r.daysRequested}</td><td><Badge s={r.status} /></td><td>{r.status === 'pending' && <Button size="sm" onClick={() => void act(r.id, 'approve')}>Approve</Button>}</td></tr>))}</tbody></table>)}
  </Card>);
}

function PayrollTab({ fac }: { fac: string | null }) {
  const exps = useFetch(() => hrApi.payrollExports(fac), [fac]);
  const all = useMemo(() => (exps.data ?? []) as any[], [exps.data]);
  return (<Card title="Payroll Exports">
    {all.length === 0 ? <EmptyState title="No exports" body="Generate payroll exports." /> : (
      <table className="data-table"><thead><tr><th>Start</th><th>End</th><th>Rows</th><th>Format</th><th>Exported</th></tr></thead>
        <tbody>{all.map((e: any) => (<tr key={e.id}><td>{e.periodStart}</td><td>{e.periodEnd}</td><td>{e.rowCount}</td><td><Badge s={e.format} /></td><td>{e.exportedAt ? new Date(e.exportedAt).toLocaleString() : '---'}</td></tr>))}</tbody></table>)}
  </Card>);
}
