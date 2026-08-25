import { useCallback, useMemo, useState } from 'react';
import { useFetch } from '../hooks/useFetch';
import { api } from '../api/client';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/orchestration.css';

const orchestrationApi = {
  dashboard: () => api.request<Record<string, unknown>>('/api/v1/orchestration/dashboard'),
  listQueue: (dept?: string) => {
    const qs = dept ? `?department=${dept}` : '';
    return api.request<{ data: unknown[] }>(`/api/v1/orchestration/queue${qs}`);
  },
  enqueue: (p: Record<string, unknown>) => api.request<unknown>('/api/v1/orchestration/queue', { method: 'POST', body: p }),
  callNext: (dept: string) => api.request<unknown>(`/api/v1/orchestration/queue/${dept}/call-next`, { method: 'POST', body: {} }),
  startConsultation: (id: string) => api.request<unknown>(`/api/v1/orchestration/queue/${id}/start`, { method: 'POST', body: {} }),
  completeQueue: (id: string) => api.request<unknown>(`/api/v1/orchestration/queue/${id}/complete`, { method: 'POST', body: {} }),
  listBookings: (type?: string) => {
    const qs = type ? `?resource_type=${type}` : '';
    return api.request<{ data: unknown[] }>(`/api/v1/orchestration/bookings${qs}`);
  },
  bookResource: (p: Record<string, unknown>) => api.request<unknown>('/api/v1/orchestration/bookings', { method: 'POST', body: p }),
  cancelBooking: (id: string) => api.request<unknown>(`/api/v1/orchestration/bookings/${id}/cancel`, { method: 'POST', body: {} }),
  capacity: () => api.request<Record<string, unknown>>('/api/v1/orchestration/capacity'),
  patientFlow: () => api.request<Record<string, unknown>>('/api/v1/orchestration/patient-flow'),
};

type Tab = 'dashboard' | 'queue' | 'resources' | 'flow';

export function OrchestrationPage() {
    const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<Tab>('dashboard');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [queueDept, setQueueDept] = useState('general');

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  const dash = useFetch(() => orchestrationApi.dashboard().catch(() => ({})), []);
  const cap = useFetch(() => orchestrationApi.capacity().catch(() => ({})), []);
  const flow = useFetch(() => orchestrationApi.patientFlow().catch(() => ({})), []);
  const queue = useFetch(() => orchestrationApi.listQueue(queueDept).then(r => r?.data ?? []).catch(() => []), [queueDept]);
  const bookings = useFetch(() => orchestrationApi.listBookings().then(r => r?.data ?? []).catch(() => []), []);

  const d = useMemo(() => (dash.data ?? {}) as Record<string, unknown>, [dash.data]);
  const c = useMemo(() => (cap.data ?? {}) as Record<string, unknown>, [cap.data]);
  const f = useMemo(() => (flow.data ?? {}) as Record<string, unknown>, [flow.data]);
  const q = useMemo(() => (queue.data ?? []) as Array<Record<string, unknown>>, [queue.data]);
  const b = useMemo(() => (bookings.data ?? []) as Array<Record<string, unknown>>, [bookings.data]);

  const bedCap = (c.beds ?? {}) as Record<string, unknown>;
  const appCap = (c.appointments ?? {}) as Record<string, unknown>;
  const otCap = (c.theatres ?? {}) as Record<string, unknown>;

  const [enqueueForm, setEnqueueForm] = useState({ patient_id: '', department: 'general', priority: 'normal' });
  const [bookForm, setBookForm] = useState({ resource_type: 'ot', resource_id: '', title: '', starts_at: '', ends_at: '' });

  return (
    <div className="page orch-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Hospital Operations</h1>
          <p className="page__subtitle">Queue, resources, capacity, patient flow</p>
        </div>
        <div className="orch-actions">
          <Button variant="ghost" onClick={() => { dash.refresh(); cap.refresh(); flow.refresh(); queue.refresh(); bookings.refresh(); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      <div className="orch-tabs">
        {(['dashboard', 'queue', 'resources', 'flow'] as const).map(t => (
          <button key={t} className={`orch-tab ${activeTab === t ? 'orch-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'dashboard' ? 'Overview' : t === 'queue' ? 'Queue' : t === 'resources' ? 'Resources' : 'Patient Flow'}
          </button>
        ))}
      </div>

      {/* ── Dashboard Tab ─────────────────────────────────── */}
      {activeTab === 'dashboard' && (
        <div className="orch-dashboard">
          <div className="orch-census">
            <div className="orch-census-card"><span className="orch-census-value">{(bedCap.total as number) ?? 0}</span><span className="orch-census-label">Total Beds</span></div>
            <div className="orch-census-card"><span className="orch-census-value" style={{color:'#10b981'}}>{(bedCap.available as number) ?? 0}</span><span className="orch-census-label">Available Beds</span></div>
            <div className="orch-census-card"><span className="orch-census-value" style={{color:'#f59e0b'}}>{(bedCap.occupancy_pct as number) ?? 0}%</span><span className="orch-census-label">Bed Occupancy</span></div>
            <div className="orch-census-card"><span className="orch-census-value">{(otCap.total as number) ?? 0}</span><span className="orch-census-label">Active Theatres</span></div>
            <div className="orch-census-card"><span className="orch-census-value">{(otCap.booked_today as number) ?? 0}</span><span className="orch-census-label">OT Booked Today</span></div>
            <div className="orch-census-card"><span className="orch-census-value">{(appCap.today as number) ?? 0}</span><span className="orch-census-label">Appointments Today</span></div>
            <div className="orch-census-card"><span className="orch-census-value">{(appCap.remaining as number) ?? 0}</span><span className="orch-census-label">Remaining</span></div>
            <div className="orch-census-card"><span className="orch-census-value">{d.queue_depth as number ?? 0}</span><span className="orch-census-label">Queue Depth</span></div>
            <div className="orch-census-card"><span className="orch-census-value">{d.pending_referrals as number ?? 0}</span><span className="orch-census-label">Pending Referrals</span></div>
          </div>

          <Card className="orch-section-card">
            <div className="orch-section-header"><h3>Patient Flow</h3></div>
            <div className="orch-flow-strip">
              {[
                { label: 'Arrivals', value: f.arrivals as number ?? 0, color: '#3b82f6' },
                { label: 'Checked In', value: f.checked_in as number ?? 0, color: '#8b5cf6' },
                { label: 'In Consultation', value: f.in_consultation as number ?? 0, color: '#f59e0b' },
                { label: 'Completed', value: f.completed as number ?? 0, color: '#10b981' },
                { label: 'Cancelled', value: f.cancelled as number ?? 0, color: '#ef4444' },
                { label: 'No Show', value: f.no_show as number ?? 0, color: '#6b7280' },
              ].map(item => (
                <div key={item.label} className="orch-flow-item" style={{borderLeftColor: item.color}}>
                  <span className="orch-flow-value" style={{color: item.color}}>{item.value}</span>
                  <span className="orch-flow-label">{item.label}</span>
                </div>
              ))}
            </div>
          </Card>
        </div>
      )}

      {/* ── Queue Tab ──────────────────────────────────────── */}
      {activeTab === 'queue' && (
        <Card className="orch-section-card">
          <div className="orch-section-header">
            <h3>Queue — {queueDept}</h3>
            <div style={{display:'flex',gap:'0.5rem',alignItems:'center'}}>
              <select className="q-input" value={queueDept} onChange={e => setQueueDept(e.target.value)}>
                {['general','opd','emergency','radiology','laboratory','pharmacy'].map(d => <option key={d} value={d}>{d}</option>)}
              </select>
              <Button variant="primary" size="sm" onClick={() => setDlg('enqueue')}>+ Enqueue</Button>
              <Button variant="ghost" size="sm" onClick={async () => { await go(() => orchestrationApi.callNext(queueDept)); queue.refresh(); }}>Call Next</Button>
            </div>
          </div>
          {q.length === 0 ? <EmptyState title="No patients in queue" body="Enqueue patients or call the next patient from the waiting room." /> : (
            <div className="orch-table">
              <div className="orch-table-header"><span>Token</span><span>Patient</span><span>Priority</span><span>Status</span><span>Actions</span></div>
              {q.map((e: Record<string, unknown>) => (
                <div key={e.id as string} className="orch-table-row">
                  <span className="orch-mono">#{e.token_number as number}</span>
                  <span>{(e.patient as Record<string, unknown>)?.first_name as string ?? '—'} {(e.patient as Record<string, unknown>)?.last_name as string ?? ''}</span>
                  <span className={`orch-badge orch-badge--${e.priority}`}>{e.priority as string}</span>
                  <span className={`orch-badge orch-badge--${e.status}`}>{(e.status as string).replace(/_/g,' ')}</span>
                  <span className="orch-table-actions">
                    {e.status === 'waiting' && <Button variant="ghost" size="sm" onClick={async () => { await go(() => orchestrationApi.startConsultation(e.id as string)); queue.refresh(); }}>Start</Button>}
                    {e.status === 'in_progress' && <Button variant="ghost" size="sm" onClick={async () => { await go(() => orchestrationApi.completeQueue(e.id as string)); queue.refresh(); dash.refresh(); }}>Complete</Button>}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Resources Tab ─────────────────────────────────── */}
      {activeTab === 'resources' && (
        <Card className="orch-section-card">
          <div className="orch-section-header">
            <h3>Resource Bookings</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('book')}>+ Book Resource</Button>
          </div>
          {b.length === 0 ? <EmptyState title="No bookings" body="Book operating theatres, imaging machines, equipment, or rooms." /> : (
            <div className="orch-table">
              <div className="orch-table-header"><span>Code</span><span>Title</span><span>Type</span><span>Status</span><span>Start</span><span>End</span><span>Actions</span></div>
              {b.map((bk: Record<string, unknown>) => (
                <div key={bk.id as string} className="orch-table-row">
                  <span className="orch-mono">{bk.booking_code as string}</span>
                  <span>{bk.title as string}</span>
                  <span className={`orch-badge orch-badge--${bk.resource_type}`}>{bk.resource_type as string}</span>
                  <span className={`orch-badge orch-badge--${bk.status}`}>{bk.status as string}</span>
                  <span>{bk.starts_at ? new Date(bk.starts_at as string).toLocaleString() : '—'}</span>
                  <span>{bk.ends_at ? new Date(bk.ends_at as string).toLocaleString() : '—'}</span>
                  <span className="orch-table-actions">
                    {bk.status !== 'cancelled' && bk.status !== 'completed' && (
                      <Button variant="ghost" size="sm" onClick={async () => { await go(() => orchestrationApi.cancelBooking(bk.id as string)); bookings.refresh(); }}>Cancel</Button>
                    )}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Patient Flow Tab ───────────────────────────────── */}
      {activeTab === 'flow' && (
        <Card className="orch-section-card">
          <div className="orch-section-header"><h3>Patient Flow — Today</h3></div>
          <div className="orch-flow-strip">
            {[
              { label: 'Arrivals', value: f.arrivals as number ?? 0, color: '#3b82f6' },
              { label: 'Checked In', value: f.checked_in as number ?? 0, color: '#8b5cf6' },
              { label: 'In Consultation', value: f.in_consultation as number ?? 0, color: '#f59e0b' },
              { label: 'Completed', value: f.completed as number ?? 0, color: '#10b981' },
              { label: 'Cancelled', value: f.cancelled as number ?? 0, color: '#ef4444' },
              { label: 'No Show', value: f.no_show as number ?? 0, color: '#6b7280' },
              { label: 'Queue Waiting', value: f.waiting_in_queue as number ?? 0, color: '#3b82f6' },
              { label: 'Queue In Progress', value: f.in_queue_consultation as number ?? 0, color: '#f59e0b' },
            ].map(item => (
              <div key={item.label} className="orch-flow-item" style={{borderLeftColor: item.color}}>
                <span className="orch-flow-value" style={{color: item.color}}>{item.value}</span>
                <span className="orch-flow-label">{item.label}</span>
              </div>
            ))}
          </div>
          {Object.keys(f).length === 0 && <EmptyState title="No flow data" body="Patient flow data will appear when appointments and queue entries exist." />}
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}
      {dlg === 'enqueue' && (
        <Dialog open onClose={() => setDlg(null)} title="Enqueue Patient" footer={<>
          <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
          <Button onClick={async () => {
            await go(() => orchestrationApi.enqueue(enqueueForm));
            setDlg(null); setEnqueueForm({ patient_id: '', department: 'general', priority: 'normal' }); queue.refresh();
          }} loading={busy} disabled={!enqueueForm.patient_id}>Enqueue</Button>
        </>}>
          <div className="orch-form">
            <Input label="Patient ID" value={enqueueForm.patient_id} onChange={e => setEnqueueForm(f => ({ ...f, patient_id: e.target.value }))} placeholder="UUID" required />
            <div className="orch-form-field">
              <label className="orch-label">Department</label>
              <select className="q-input" value={enqueueForm.department} onChange={e => setEnqueueForm(f => ({ ...f, department: e.target.value }))}>
                {['general','opd','emergency','radiology','laboratory','pharmacy'].map(d => <option key={d} value={d}>{d}</option>)}
              </select>
            </div>
            <div className="orch-form-field">
              <label className="orch-label">Priority</label>
              <select className="q-input" value={enqueueForm.priority} onChange={e => setEnqueueForm(f => ({ ...f, priority: e.target.value }))}>
                {['emergency','urgent','normal','routine'].map(p => <option key={p} value={p}>{p}</option>)}
              </select>
            </div>
          </div>
        </Dialog>
      )}

      {dlg === 'book' && (
        <Dialog open onClose={() => setDlg(null)} title="Book Resource" footer={<>
          <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
          <Button onClick={async () => {
            await go(() => orchestrationApi.bookResource(bookForm));
            setDlg(null); setBookForm({ resource_type: 'ot', resource_id: '', title: '', starts_at: '', ends_at: '' }); bookings.refresh();
          }} loading={busy} disabled={!bookForm.title}>Book</Button>
        </>}>
          <div className="orch-form">
            <div className="orch-form-field">
              <label className="orch-label">Resource Type</label>
              <select className="q-input" value={bookForm.resource_type} onChange={e => setBookForm(f => ({ ...f, resource_type: e.target.value }))}>
                {['ot','imaging','equipment','room','bed'].map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <Input label="Resource ID" value={bookForm.resource_id} onChange={e => setBookForm(f => ({ ...f, resource_id: e.target.value }))} placeholder="UUID" required />
            <Input label="Title" value={bookForm.title} onChange={e => setBookForm(f => ({ ...f, title: e.target.value }))} placeholder="e.g. MRI Scan - Patient X" required />
            <Input label="Start" type="datetime-local" value={bookForm.starts_at} onChange={e => setBookForm(f => ({ ...f, starts_at: e.target.value }))} required />
            <Input label="End" type="datetime-local" value={bookForm.ends_at} onChange={e => setBookForm(f => ({ ...f, ends_at: e.target.value }))} required />
          </div>
        </Dialog>
      )}
    </div>
  );
}
