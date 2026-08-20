import { useState } from 'react';
import { nursingApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import './pages.css';

const TASK_TYPES = [
  { value: 'vitals', label: 'Vitals' }, { value: 'medication', label: 'Medication' },
  { value: 'wound_care', label: 'Wound Care' }, { value: 'mobility', label: 'Mobility' },
  { value: 'hydration', label: 'Hydration' }, { value: 'documentation', label: 'Documentation' },
  { value: 'discharge_prep', label: 'Discharge Prep' },
];
const PRIORITIES = [{ value: 'routine', label: 'Routine' }, { value: 'urgent', label: 'Urgent' }, { value: 'stat', label: 'STAT' }];
const SHIFTS = [{ value: 'morning', label: 'Morning' }, { value: 'afternoon', label: 'Afternoon' }, { value: 'night', label: 'Night' }];
const ALERT_TYPES = [
  { value: 'critical_value', label: 'Critical Value' }, { value: 'overdue_task', label: 'Overdue Task' },
  { value: 'fall_risk', label: 'Fall Risk' }, { value: 'escalation', label: 'Escalation' },
  { value: 'medication_due', label: 'Medication Due' }, { value: 'discharge_ready', label: 'Discharge Ready' },
];
const SC: Record<string, string> = { info: '#3b82f6', warning: '#f59e0b', critical: '#ef4444' };
const TC: Record<string, string> = { pending: '#f59e0b', in_progress: '#3b82f6', completed: '#10b981', overdue: '#ef4444' };

export function NursingPage() {
  const tasks = useFetch(() => nursingApi.tasks(), ['nt']);
  const vitals = useFetch(() => nursingApi.vitals(), ['nv']);
  const plans = useFetch(() => nursingApi.carePlans(), ['ncp']);
  const handovers = useFetch(() => nursingApi.handovers(), ['nh']);
  const alertsData = useFetch(() => nursingApi.alerts(), ['na']);
  const [error, setError] = useState<string | null>(null);
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [tP, setTP] = useState(''); const [tT, setTT] = useState('vitals'); const [tD, setTD] = useState('');
  const [tPr, setTPr] = useState('routine'); const [tA, setTA] = useState(''); const [tDu, setTDu] = useState('');
  const [vP, setVP] = useState(''); const [vS, setVS] = useState(''); const [vTe, setVTe] = useState('');
  const [vHr, setVHr] = useState(''); const [vSB, setVSB] = useState(''); const [vDB, setVDB] = useState('');
  const [vSp, setVSp] = useState(''); const [vPa, setVPa] = useState(''); const [vGc, setVGc] = useState('');
  const [cP, setCP] = useState(''); const [cS, setCS] = useState(''); const [cDi, setCDi] = useState('');
  const [cGo, setCGo] = useState(''); const [cIn, setCIn] = useState('');
  const [hO, setHO] = useState(''); const [hI, setHI] = useState(''); const [hSh, setHSh] = useState('morning');
  const [hDa, setHDa] = useState(new Date().toISOString().split('T')[0]); const [hSu, setHSu] = useState('');
  const [hCr, setHCr] = useState('');
  const [aP, setAP] = useState(''); const [aTo, setATo] = useState(''); const [aTy, setATy] = useState('critical_value');
  const [aSe, setASe] = useState('warning'); const [aM, setAM] = useState('');

  const go = async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  };
  const now = () => new Date().toISOString();
  const unreadAlerts = (alertsData.data ?? []).filter((a) => a.status === 'unread');
  const pendingTasks = (tasks.data ?? []).filter((t) => t.status === 'pending');

  return (
    <div className="page-container">
      <div className="page-header">
        <div><h1 className="page-title">Nursing</h1><p className="page-subtitle">Tasks, vitals, care plans, handover, alerts</p></div>
        <div className="page-actions">
          <Button variant="secondary" onClick={() => { setTP(''); setTD(''); setTA(''); setDlg('task'); }}>New Task</Button>
          <Button variant="secondary" onClick={() => { setVP(''); setVS(''); setVTe(''); setVHr(''); setVSB(''); setVDB(''); setVSp(''); setVPa(''); setVGc(''); setDlg('vital'); }}>Record Vitals</Button>
          <Button variant="secondary" onClick={() => { setCP(''); setCS(''); setCDi(''); setCGo(''); setCIn(''); setDlg('careplan'); }}>Care Plan</Button>
          <Button variant="secondary" onClick={() => { setHO(''); setHI(''); setHSu(''); setHCr(''); setDlg('handover'); }}>Handover</Button>
          <Button variant="secondary" onClick={() => { setAP(''); setATo(''); setAM(''); setDlg('alert'); }}>New Alert</Button>
        </div>
      </div>
      {error && <Alert tone="danger">{error}</Alert>}
      <div className="stats-grid">
        <Card className="stat-card"><div className="stat-label">Tasks Pending</div><div className="stat-value" style={{ color: TC.pending }}>{tasks.loading ? '—' : pendingTasks.length}</div></Card>
        <Card className="stat-card"><div className="stat-label">Vitals</div><div className="stat-value">{vitals.loading ? '—' : (vitals.data ?? []).length}</div></Card>
        <Card className="stat-card"><div className="stat-label">Care Plans</div><div className="stat-value">{plans.loading ? '—' : (plans.data ?? []).filter((p) => p.status === 'active').length}</div></Card>
        <Card className="stat-card"><div className="stat-label">Unread Alerts</div><div className="stat-value" style={{ color: unreadAlerts.length > 0 ? SC.critical : 'inherit' }}>{alertsData.loading ? '—' : unreadAlerts.length}</div></Card>
      </div>
      {tasks.loading ? <SkeletonTable rows={5} cols={6} /> : (tasks.data ?? []).length === 0 ? (
        <EmptyState title="No tasks" body="Create a nursing task to begin." />
      ) : (
        <Card><h3 className="card-header">Tasks</h3>
          <table className="data-table"><thead><tr><th>Type</th><th>Description</th><th>Patient</th><th>Priority</th><th>Status</th><th>Due</th><th>Actions</th></tr></thead><tbody>
            {(tasks.data ?? []).map((t) => <tr key={t.id}>
              <td>{TASK_TYPES.find((x) => x.value === t.taskType)?.label ?? t.taskType}</td>
              <td style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{t.description}</td>
              <td>{t.patientId.slice(0, 8)}…</td>
              <td><span className={`badge badge--${t.priority === 'stat' ? 'danger' : t.priority === 'urgent' ? 'warning' : 'neutral'}`}>{t.priority}</span></td>
              <td><span className="badge" style={{ color: TC[t.status] ?? '#6b7280' }}>{t.status}</span></td>
              <td>{t.dueAt ? new Date(t.dueAt).toLocaleString() : '—'}</td>
              <td>{t.status === 'pending' && <Button size="sm" onClick={async () => { await go(() => nursingApi.completeTask(t.id, { completedBy: 'current-user' })); tasks.refresh(); }}>Done</Button>}</td>
            </tr>)}
          </tbody></table>
        </Card>
      )}
      {(alertsData.data ?? []).length > 0 && (
        <Card><h3 className="card-header">Alerts</h3>
          {(alertsData.data ?? []).map((a) => (
            <div key={a.id} style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', padding: '0.5rem 0', borderBottom: '1px solid var(--border)' }}>
              <span style={{ width: 8, height: 8, borderRadius: '50%', background: SC[a.severity] ?? '#6b7280', flexShrink: 0 }} />
              <div style={{ flex: 1 }}><span className="badge badge--neutral" style={{ marginRight: '0.5rem' }}>{a.alertType}</span><span style={{ fontSize: '0.9rem' }}>{a.message}</span></div>
              {a.status === 'unread' && <Button size="sm" variant="secondary" onClick={async () => { await go(() => nursingApi.acknowledgeAlert(a.id)); alertsData.refresh(); }}>Ack</Button>}
            </div>
          ))}
        </Card>
      )}
      <Dialog open={dlg === 'task'} onClose={() => setDlg(null)} title="New Nursing Task">
        <div className="dialog-form">
          <Input label="Patient ID" value={tP} onChange={(e) => setTP(e.target.value)} placeholder="Patient UUID" />
          <Select label="Task Type" value={tT} onChange={(e) => setTT(e.target.value)}>{TASK_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</Select>
          <Input label="Description" value={tD} onChange={(e) => setTD(e.target.value)} placeholder="Description" />
          <Select label="Priority" value={tPr} onChange={(e) => setTPr(e.target.value)}>{PRIORITIES.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}</Select>
          <Input label="Assigned To" value={tA} onChange={(e) => setTA(e.target.value)} placeholder="Staff UUID" />
          <Input label="Due At" type="datetime-local" value={tDu} onChange={(e) => setTDu(e.target.value)} />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await nursingApi.createTask({ patientId: tP.trim(), taskType: tT, description: tD.trim(), priority: tPr, assignedTo: tA.trim() || undefined, dueAt: tDu ? new Date(tDu).toISOString() : undefined }); setDlg(null); tasks.refresh(); }); }} disabled={busy || !tP.trim() || !tD.trim()}>{busy ? '…' : 'Create'}</Button></div>
        </div>
      </Dialog>
      <Dialog open={dlg === 'vital'} onClose={() => setDlg(null)} title="Record Vital Signs">
        <div className="dialog-form">
          <Input label="Patient ID" value={vP} onChange={(e) => setVP(e.target.value)} placeholder="Patient UUID" />
          <Input label="Recorded By" value={vS} onChange={(e) => setVS(e.target.value)} placeholder="Staff UUID" />
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
            <Input label="Temp (C)" type="number" step="0.1" value={vTe} onChange={(e) => setVTe(e.target.value)} placeholder="36.5" />
            <Input label="Heart Rate" type="number" value={vHr} onChange={(e) => setVHr(e.target.value)} placeholder="72" />
            <Input label="BP Systolic" type="number" value={vSB} onChange={(e) => setVSB(e.target.value)} placeholder="120" />
            <Input label="BP Diastolic" type="number" value={vDB} onChange={(e) => setVDB(e.target.value)} placeholder="80" />
            <Input label="SpO2 %" type="number" step="0.1" value={vSp} onChange={(e) => setVSp(e.target.value)} placeholder="98" />
            <Input label="Pain (0-10)" type="number" value={vPa} onChange={(e) => setVPa(e.target.value)} placeholder="0" />
            <Input label="GCS (3-15)" type="number" value={vGc} onChange={(e) => setVGc(e.target.value)} placeholder="15" />
          </div>
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await nursingApi.recordVital({ patientId: vP.trim(), recordedBy: vS.trim(), observedAt: now(), temperatureCelsius: vTe ? parseFloat(vTe) : undefined, heartRateBpm: vHr ? parseInt(vHr) : undefined, systolicBp: vSB ? parseInt(vSB) : undefined, diastolicBp: vDB ? parseInt(vDB) : undefined, spo2Percent: vSp ? parseFloat(vSp) : undefined, painScore: vPa ? parseInt(vPa) : undefined, gcsScore: vGc ? parseInt(vGc) : undefined }); setDlg(null); vitals.refresh(); }); }} disabled={busy || !vP.trim() || !vS.trim()}>{busy ? '…' : 'Record'}</Button></div>
        </div>
      </Dialog>
      <Dialog open={dlg === 'careplan'} onClose={() => setDlg(null)} title="New Care Plan">
        <div className="dialog-form">
          <Input label="Patient ID" value={cP} onChange={(e) => setCP(e.target.value)} placeholder="Patient UUID" />
          <Input label="Created By" value={cS} onChange={(e) => setCS(e.target.value)} placeholder="Staff UUID" />
          <Input label="Diagnosis" value={cDi} onChange={(e) => setCDi(e.target.value)} placeholder="Diagnosis" />
          <Input label="Goals" value={cGo} onChange={(e) => setCGo(e.target.value)} placeholder="Goals" />
          <Input label="Interventions" value={cIn} onChange={(e) => setCIn(e.target.value)} placeholder="Interventions" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await nursingApi.createCarePlan({ patientId: cP.trim(), createdBy: cS.trim(), diagnosis: cDi.trim(), goals: cGo.trim(), interventions: cIn.trim(), effectiveFrom: now() }); setDlg(null); plans.refresh(); }); }} disabled={busy || !cP.trim() || !cDi.trim()}>{busy ? '…' : 'Create'}</Button></div>
        </div>
      </Dialog>
      <Dialog open={dlg === 'handover'} onClose={() => setDlg(null)} title="Shift Handover">
        <div className="dialog-form">
          <Input label="Outgoing Staff" value={hO} onChange={(e) => setHO(e.target.value)} placeholder="Staff UUID" />
          <Input label="Incoming Staff" value={hI} onChange={(e) => setHI(e.target.value)} placeholder="Staff UUID" />
          <Select label="Shift" value={hSh} onChange={(e) => setHSh(e.target.value)}>{SHIFTS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}</Select>
          <Input label="Date" type="date" value={hDa} onChange={(e) => setHDa(e.target.value)} />
          <Input label="Patient Summaries" value={hSu} onChange={(e) => setHSu(e.target.value)} placeholder="Patient status" />
          <Input label="Critical Items" value={hCr} onChange={(e) => setHCr(e.target.value)} placeholder="Critical items" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await nursingApi.createHandover({ outgoingStaffId: hO.trim(), incomingStaffId: hI.trim(), shift: hSh, handoverDate: hDa, patientSummaries: hSu.trim(), criticalItems: hCr || undefined }); setDlg(null); handovers.refresh(); }); }} disabled={busy || !hO.trim() || !hI.trim() || !hSu.trim()}>{busy ? '…' : 'Submit'}</Button></div>
        </div>
      </Dialog>
      <Dialog open={dlg === 'alert'} onClose={() => setDlg(null)} title="New Alert">
        <div className="dialog-form">
          <Input label="Patient ID" value={aP} onChange={(e) => setAP(e.target.value)} placeholder="Patient UUID" />
          <Input label="Alert To" value={aTo} onChange={(e) => setATo(e.target.value)} placeholder="Staff UUID" />
          <Select label="Type" value={aTy} onChange={(e) => setATy(e.target.value)}>{ALERT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</Select>
          <Select label="Severity" value={aSe} onChange={(e) => setASe(e.target.value)}>
            <option value="info">Info</option><option value="warning">Warning</option><option value="critical">Critical</option>
          </Select>
          <Input label="Message" value={aM} onChange={(e) => setAM(e.target.value)} placeholder="Alert message" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await nursingApi.createAlert({ patientId: aP.trim(), alertTo: aTo.trim(), alertType: aTy, severity: aSe, message: aM.trim() }); setDlg(null); alertsData.refresh(); }); }} disabled={busy || !aP.trim() || !aTo.trim() || !aM.trim()}>{busy ? '…' : 'Create'}</Button></div>
        </div>
      </Dialog>
    </div>
  );
}
