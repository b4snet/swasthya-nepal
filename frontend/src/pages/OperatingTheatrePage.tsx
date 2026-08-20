import { useCallback, useState } from 'react';
import { otApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import './pages.css';

const PRIORITY_OPTIONS = [
  { value: 'routine', label: 'Routine' },
  { value: 'urgent', label: 'Urgent' },
  { value: 'emergency', label: 'Emergency' },
];

const ANESTHESIA_TYPES = [
  { value: 'general', label: 'General' },
  { value: 'spinal', label: 'Spinal' },
  { value: 'epidural', label: 'Epidural' },
  { value: 'regional', label: 'Regional' },
  { value: 'local', label: 'Local' },
  { value: 'sedation', label: 'Conscious Sedation' },
];

const EVENT_TYPES = [
  { value: 'incision', label: 'Incision' },
  { value: 'specimen', label: 'Specimen Removed' },
  { value: 'implant', label: 'Implant Placed' },
  { value: 'complication', label: 'Complication' },
  { value: 'medication', label: 'Medication Given' },
  { value: 'blood_loss', label: 'Blood Loss' },
  { value: 'vitals_check', label: 'Vitals Check' },
  { value: 'note', label: 'Note' },
];

const SC: Record<string, string> = {
  requested: '#f59e0b', scheduled: '#3b82f6', in_progress: '#ef4444',
  completed: '#10b981', cancelled: '#6b7280', active: '#10b981', closed: '#6b7280',
};

const TEAM_ROLES = [
  { value: 'surgeon', label: 'Surgeon' },
  { value: 'assistant', label: 'Assistant' },
  { value: 'anesthetist', label: 'Anesthetist' },
  { value: 'nurse', label: 'Scrub Nurse' },
  { value: 'circulator', label: 'Circulator' },
  { value: 'tech', label: 'Surgical Tech' },
];

export function OperatingTheatrePage() {
  const theatres = useFetch(() => otApi.theatres(), ['ot-theatres']);
  const requests = useFetch(() => otApi.procedureRequests(), ['ot-requests']);
  const [error, setError] = useState<string | null>(null);

  // Dialogs
  const [dlg, setDlg] = useState<string | null>(null);
  const [detail, setDetail] = useState<Record<string, unknown> | null>(null);

  // Form state
  const [tCode, setTCode] = useState('');
  const [tName, setTName] = useState('');
  const [rPatient, setRPatient] = useState('');
  const [rProc, setRProc] = useState('');
  const [rPrio, setRPrio] = useState('routine');
  const [sTheatre, setSTheatre] = useState('');
  const [sDate, setSDate] = useState('');
  const [sDur, setSDur] = useState('60');
  const [startSurgeon, setStartSurgeon] = useState('');
  const [teamStaff, setTeamStaff] = useState('');
  const [teamRole, setTeamRole] = useState('surgeon');
  const [anesStaff, setAnesStaff] = useState('');
  const [anesType, setAnesType] = useState('general');
  const [evType, setEvType] = useState('note');
  const [evNotes, setEvNotes] = useState('');
  const [busy, setBusy] = useState(false);

  const withError = async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Operation failed'); return null; } finally { setBusy(false); }
  };

  const refresh = () => { theatres.refresh(); requests.refresh(); };
  const loadDetail = useCallback(async (id: string) => { const d = await withError(() => otApi.showProcedure(id)); if (d) { setDetail(d as unknown as Record<string, unknown>); setDlg('detail'); } }, []);

  const pending = (requests.data ?? []).filter((r) => r.status === 'requested');
  const scheduled = (requests.data ?? []).filter((r) => r.status === 'scheduled');
  const active = (requests.data ?? []).filter((r) => r.status === 'in_progress');
  const done = (requests.data ?? []).filter((r) => r.status === 'completed');

  return (
    <div className="page-container">
      <div className="page-header">
        <div><h1 className="page-title">Operating Theatre</h1><p className="page-subtitle">Surgical scheduling, workflow, and documentation</p></div>
        <div className="page-actions">
          <Button variant="secondary" onClick={() => setDlg('theatre')}>Add Theatre</Button>
          <Button onClick={() => setDlg('request')}>New Procedure Request</Button>
        </div>
      </div>
      {error && <Alert tone="danger">{error}</Alert>}

      <div className="stats-grid">
        <Card className="stat-card"><div className="stat-label">Theatres</div><div className="stat-value">{theatres.loading ? '—' : (theatres.data ?? []).length}</div></Card>
        <Card className="stat-card"><div className="stat-label">Pending</div><div className="stat-value" style={{ color: SC.requested }}>{requests.loading ? '—' : pending.length}</div></Card>
        <Card className="stat-card"><div className="stat-label">Scheduled</div><div className="stat-value" style={{ color: SC.scheduled }}>{requests.loading ? '—' : scheduled.length}</div></Card>
        <Card className="stat-card"><div className="stat-label">In Progress</div><div className="stat-value" style={{ color: SC.in_progress }}>{requests.loading ? '—' : active.length}</div></Card>
        <Card className="stat-card"><div className="stat-label">Completed</div><div className="stat-value" style={{ color: SC.completed }}>{requests.loading ? '—' : done.length}</div></Card>
      </div>

      {theatres.loading ? <SkeletonTable rows={3} cols={3} /> : (theatres.data ?? []).length === 0 ? (
        <EmptyState title="No theatres" body="Add an operating theatre to begin scheduling." />
      ) : (
        <Card><h3 className="card-header">Theatres</h3>
          <table className="data-table"><thead><tr><th>Code</th><th>Name</th><th>Status</th></tr></thead><tbody>
            {(theatres.data ?? []).map((t) => <tr key={t.id}><td className="font-medium">{t.code}</td><td>{t.name}</td><td><span className="badge" style={{ color: SC[t.status] ?? '#6b7280' }}>{t.status}</span></td></tr>)}
          </tbody></table>
        </Card>
      )}

      {requests.loading ? <SkeletonTable rows={5} cols={6} /> : (requests.data ?? []).length === 0 ? (
        <EmptyState title="No procedure requests" body="Create a procedure request to begin." />
      ) : (
        <Card><h3 className="card-header">Procedure Requests</h3>
          <table className="data-table"><thead><tr><th>Procedure</th><th>Patient</th><th>Priority</th><th>Status</th><th>Scheduled</th><th>Actions</th></tr></thead><tbody>
            {(requests.data ?? []).map((r) => <tr key={r.id}>
              <td className="font-medium">{r.procedureName}</td><td>{r.patientId.slice(0, 8)}…</td>
              <td><span className={`badge badge--${r.priority === 'emergency' ? 'danger' : r.priority === 'urgent' ? 'warning' : 'neutral'}`}>{r.priority}</span></td>
              <td><span className="badge" style={{ color: SC[r.status] ?? '#6b7280' }}>{r.status}</span></td>
              <td>{r.scheduledAt ? new Date(r.scheduledAt).toLocaleString() : '—'}</td>
              <td><div className="flex gap-2">
                {r.status === 'requested' && <Button size="sm" variant="secondary" onClick={() => { setSTheatre(''); setSDate(''); setSDur('60'); setDlg('schedule'); (window as unknown as Record<string, string>)._schedId = r.id; }}>Schedule</Button>}
                {r.status === 'scheduled' && <Button size="sm" onClick={() => { setStartSurgeon(''); setDlg('start'); (window as unknown as Record<string, string>)._startId = r.id; }}>Start</Button>}
                {(r.status === 'in_progress' || r.status === 'completed') && <Button size="sm" variant="secondary" onClick={() => loadDetail(r.id)}>View</Button>}
              </div></td>
            </tr>)}
          </tbody></table>
        </Card>
      )}

      {/* Create Theatre */}
      <Dialog open={dlg === 'theatre'} onClose={() => setDlg(null)} title="Add Theatre">
        <div className="dialog-form">
          <Input label="Code" value={tCode} onChange={(e) => setTCode(e.target.value)} placeholder="e.g. OT-1" />
          <Input label="Name" value={tName} onChange={(e) => setTName(e.target.value)} placeholder="e.g. Main OT" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await withError(async () => { await otApi.createTheatre({ code: tCode.trim(), name: tName.trim() }); setDlg(null); setTCode(''); setTName(''); refresh(); }); }} disabled={busy || !tCode.trim() || !tName.trim()}>{busy ? '…' : 'Create'}</Button>
          </div>
        </div>
      </Dialog>

      {/* New Request */}
      <Dialog open={dlg === 'request'} onClose={() => setDlg(null)} title="New Procedure Request">
        <div className="dialog-form">
          <Input label="Patient ID" value={rPatient} onChange={(e) => setRPatient(e.target.value)} placeholder="Patient UUID" />
          <Input label="Procedure" value={rProc} onChange={(e) => setRProc(e.target.value)} placeholder="e.g. Appendectomy" />
          <Select label="Priority" value={rPrio} onChange={(e) => setRPrio(e.target.value)}>
            {PRIORITY_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await withError(async () => { await otApi.createProcedureRequest({ patientId: rPatient.trim(), procedureName: rProc.trim(), priority: rPrio }); setDlg(null); setRPatient(''); setRProc(''); refresh(); }); }} disabled={busy || !rPatient.trim() || !rProc.trim()}>{busy ? '…' : 'Submit'}</Button>
          </div>
        </div>
      </Dialog>

      {/* Schedule */}
      <Dialog open={dlg === 'schedule'} onClose={() => setDlg(null)} title="Schedule Procedure">
        <div className="dialog-form">
          <Select label="Theatre" value={sTheatre} onChange={(e) => setSTheatre(e.target.value)}>
            <option value="">Select…</option>
            {(theatres.data ?? []).filter((t) => t.status === 'active').map((t) => <option key={t.id} value={t.id}>{t.code} — {t.name}</option>)}
          </Select>
          <Input label="Date & Time" type="datetime-local" value={sDate} onChange={(e) => setSDate(e.target.value)} />
          <Input label="Duration (min)" type="number" value={sDur} onChange={(e) => setSDur(e.target.value)} />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await withError(async () => { await otApi.schedule((window as unknown as Record<string, string>)._schedId, { theatreId: sTheatre, scheduledAt: new Date(sDate).toISOString(), durationMinutes: parseInt(sDur) || 60 }); setDlg(null); refresh(); }); }} disabled={busy || !sTheatre || !sDate}>{busy ? '…' : 'Schedule'}</Button>
          </div>
        </div>
      </Dialog>

      {/* Start */}
      <Dialog open={dlg === 'start'} onClose={() => setDlg(null)} title="Start Procedure">
        <div className="dialog-form">
          <Input label="Surgeon Staff ID" value={startSurgeon} onChange={(e) => setStartSurgeon(e.target.value)} placeholder="Staff UUID" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await withError(async () => { await otApi.start((window as unknown as Record<string, string>)._startId, { surgeonStaffId: startSurgeon.trim() }); setDlg(null); refresh(); }); }} disabled={busy || !startSurgeon.trim()}>{busy ? '…' : 'Start'}</Button>
          </div>
        </div>
      </Dialog>

      {/* Detail */}
      <Dialog open={dlg === 'detail'} onClose={() => { setDlg(null); setDetail(null); }} title="Procedure Record">
        {detail && <div className="dialog-form">
          <div className="form-field"><label className="form-label">Status</label><span className="badge" style={{ color: SC[detail.status as string] ?? '#6b7280' }}>{detail.status as string}</span></div>
          <h4 style={{ marginTop: '1rem' }}>Surgical Team</h4>
          {(detail.team as unknown[])?.length ? (
            <table className="data-table" style={{ fontSize: '0.85rem' }}><thead><tr><th>Staff</th><th>Role</th><th>Time</th></tr></thead><tbody>
              {(detail.team as Record<string, unknown>[]).map((m) => <tr key={m.id as string}><td>{String(m.staffId).slice(0, 8)}…</td><td>{m.role as string}</td><td>{m.timeIn ? new Date(m.timeIn as string).toLocaleTimeString() : '—'}</td></tr>)}
            </tbody></table>
          ) : <p className="muted">No team members</p>}
          <Button size="sm" variant="secondary" onClick={() => { setTeamStaff(''); setDlg('team'); }} style={{ marginTop: '0.5rem' }}>Add Team Member</Button>
          <h4 style={{ marginTop: '1rem' }}>Safety Checklist</h4>
          {(detail.checklist as Record<string, unknown>[] ?? []).map((item) => (
            <div key={item.id as string} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', padding: '0.3rem 0' }}>
              <span style={{ color: item.completedAt ? '#10b981' : '#6b7280' }}>{item.completedAt ? '✓' : '○'}</span>
              <span>{item.label as string}</span>
              {!item.completedAt && detail.status === 'in_progress' && <Button size="sm" variant="secondary" onClick={async () => { await withError(() => otApi.completeChecklist(detail.id as string, item.id as string)); loadDetail(detail.id as string); }}>Complete</Button>}
            </div>
          ))}
          <h4 style={{ marginTop: '1rem' }}>Surgical Events</h4>
          {(detail.events as Record<string, unknown>[] ?? []).map((ev) => (
            <div key={ev.id as string} style={{ fontSize: '0.85rem', padding: '0.2rem 0' }}><span className="badge badge--neutral" style={{ marginRight: '0.5rem' }}>{ev.eventType as string}</span>{new Date(ev.occurredAt as string).toLocaleTimeString()}</div>
          ))}
          <div className="flex gap-2" style={{ marginTop: '1rem', flexWrap: 'wrap' }}>
            {detail.status === 'in_progress' && <>
              <Button size="sm" variant="secondary" onClick={() => { setAnesStaff(''); setDlg('anesthesia'); }}>Anesthesia</Button>
              <Button size="sm" variant="secondary" onClick={() => { setEvType('note'); setEvNotes(''); setDlg('event'); }}>Record Event</Button>
              <Button size="sm" onClick={async () => { await withError(async () => { await otApi.close(detail.id as string); setDlg(null); setDetail(null); refresh(); }); }}>{busy ? '…' : 'Close Case'}</Button>
            </>}
            {detail.status === 'completed' && <Button size="sm" variant="secondary" onClick={async () => { await withError(() => otApi.admitToRecovery(detail.id as string)); loadDetail(detail.id as string); }}>Admit to Recovery</Button>}
          </div>
        </div>}
      </Dialog>

      {/* Team */}
      <Dialog open={dlg === 'team'} onClose={() => setDlg('detail')} title="Add Team Member">
        <div className="dialog-form">
          <Input label="Staff ID" value={teamStaff} onChange={(e) => setTeamStaff(e.target.value)} placeholder="Staff UUID" />
          <Select label="Role" value={teamRole} onChange={(e) => setTeamRole(e.target.value)}>{TEAM_ROLES.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg('detail')}>Cancel</Button>
            <Button onClick={async () => { if (!detail) return; await withError(async () => { await otApi.addTeamMember(detail.id as string, { staffId: teamStaff.trim(), role: teamRole }); setDlg('detail'); setTeamStaff(''); loadDetail(detail.id as string); }); }} disabled={busy || !teamStaff.trim()}>{busy ? '…' : 'Add'}</Button>
          </div>
        </div>
      </Dialog>

      {/* Anesthesia */}
      <Dialog open={dlg === 'anesthesia'} onClose={() => setDlg('detail')} title="Start Anesthesia">
        <div className="dialog-form">
          <Input label="Anesthetist Staff ID" value={anesStaff} onChange={(e) => setAnesStaff(e.target.value)} placeholder="Staff UUID" />
          <Select label="Type" value={anesType} onChange={(e) => setAnesType(e.target.value)}>{ANESTHESIA_TYPES.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg('detail')}>Cancel</Button>
            <Button onClick={async () => { if (!detail) return; await withError(async () => { await otApi.startAnesthesia(detail.id as string, { anesthetistStaffId: anesStaff.trim(), anesthesiaType: anesType }); setDlg('detail'); setAnesStaff(''); loadDetail(detail.id as string); }); }} disabled={busy || !anesStaff.trim()}>{busy ? '…' : 'Start'}</Button>
          </div>
        </div>
      </Dialog>

      {/* Event */}
      <Dialog open={dlg === 'event'} onClose={() => setDlg('detail')} title="Record Surgical Event">
        <div className="dialog-form">
          <Select label="Event Type" value={evType} onChange={(e) => setEvType(e.target.value)}>{EVENT_TYPES.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
          <Input label="Notes" value={evNotes} onChange={(e) => setEvNotes(e.target.value)} placeholder="Optional" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg('detail')}>Cancel</Button>
            <Button onClick={async () => { if (!detail) return; await withError(async () => { await otApi.recordEvent(detail.id as string, { eventType: evType, notes: evNotes || undefined }); setDlg('detail'); setEvNotes(''); loadDetail(detail.id as string); }); }} disabled={busy}>{busy ? '…' : 'Record'}</Button>
          </div>
        </div>
      </Dialog>
    </div>
  );
}
