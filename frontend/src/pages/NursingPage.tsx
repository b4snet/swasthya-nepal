import { useState, useMemo } from 'react';
import { nursingApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import './nursing.css';

/* ── Constants ───────────────────────────────────────────────────── */

const TASK_TYPES = [
  { value: 'vitals', label: 'Vitals' },
  { value: 'medication', label: 'Medication' },
  { value: 'wound_care', label: 'Wound Care' },
  { value: 'mobility', label: 'Mobility' },
  { value: 'hydration', label: 'Hydration' },
  { value: 'documentation', label: 'Documentation' },
  { value: 'discharge_prep', label: 'Discharge Prep' },
  { value: 'assessment', label: 'Assessment' },
];

const PRIORITIES = [
  { value: 'routine', label: 'Routine' },
  { value: 'urgent', label: 'Urgent' },
  { value: 'stat', label: 'STAT' },
];

const SHIFTS = [
  { value: 'morning', label: 'Morning (07:00-14:00)' },
  { value: 'afternoon', label: 'Afternoon (14:00-21:00)' },
  { value: 'night', label: 'Night (21:00-07:00)' },
];

const ALERT_TYPES = [
  { value: 'critical_value', label: 'Critical Value' },
  { value: 'overdue_task', label: 'Overdue Task' },
  { value: 'fall_risk', label: 'Fall Risk' },
  { value: 'escalation', label: 'Escalation' },
  { value: 'medication_due', label: 'Medication Due' },
  { value: 'discharge_ready', label: 'Discharge Ready' },
];

const ALERT_SEVERITIES = [
  { value: 'info', label: 'Info' },
  { value: 'warning', label: 'Warning' },
  { value: 'critical', label: 'Critical' },
];

const STATUS_COLORS: Record<string, string> = {
  pending: '#f59e0b',
  in_progress: '#3b82f6',
  completed: '#10b981',
  overdue: '#ef4444',
};

const ALERT_COLORS: Record<string, string> = {
  info: '#3b82f6',
  warning: '#f59e0b',
  critical: '#ef4444',
};

/* ── Main Component ──────────────────────────────────────────────── */

export function NursingPage() {
  const tasks = useFetch(() => nursingApi.tasks(), ['nt']);
  const vitals = useFetch(() => nursingApi.vitals(), ['nv']);
  const plans = useFetch(() => nursingApi.carePlans(), ['ncp']);
  const handovers = useFetch(() => nursingApi.handovers(), ['nh']);
  const alertsData = useFetch(() => nursingApi.alerts(), ['na']);

  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'tasks' | 'vitals' | 'careplans' | 'handover' | 'alerts'>('tasks');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Task form
  const [tP, setTP] = useState('');
  const [tT, setTT] = useState('vitals');
  const [tD, setTD] = useState('');
  const [tPr, setTPr] = useState('routine');
  const [tA, setTA] = useState('');
  const [tDu, setTDu] = useState('');

  // Vitals form
  const [vP, setVP] = useState('');
  const [vS, setVS] = useState('');
  const [vTe, setVTe] = useState('');
  const [vHr, setVHr] = useState('');
  const [vSB, setVSB] = useState('');
  const [vDB, setVDB] = useState('');
  const [vSp, setVSp] = useState('');
  const [vPa, setVPa] = useState('');
  const [vGc, setVGc] = useState('');
  const [vAd, setVAd] = useState('');

  // Care plan form
  const [cP, setCP] = useState('');
  const [cS, setCS] = useState('');
  const [cDi, setCDi] = useState('');
  const [cGo, setCGo] = useState('');
  const [cIn, setCIn] = useState('');

  // Handover form
  const [hO, setHO] = useState('');
  const [hI, setHI] = useState('');
  const [hSh, setHSh] = useState('morning');
  const [hDa, setHDa] = useState(new Date().toISOString().split('T')[0]);
  const [hSu, setHSu] = useState('');
  const [hCr, setHCr] = useState('');
  const [hPt, setHPt] = useState('');

  // Alert form
  const [aP, setAP] = useState('');
  const [aTo, setATo] = useState('');
  const [aTy, setATy] = useState('critical_value');
  const [aSe, setASe] = useState('warning');
  const [aM, setAM] = useState('');

  const go = async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true);
    setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  };

  const now = () => new Date().toISOString();
  const allTasks = tasks.data ?? [];
  const allVitals = vitals.data ?? [];
  const allPlans = plans.data ?? [];
  const allHandovers = handovers.data ?? [];
  const allAlerts = alertsData.data ?? [];

  const pendingTasks = useMemo(() => allTasks.filter(t => t.status === 'pending'), [allTasks]);
  const urgentTasks = useMemo(() => allTasks.filter(t => t.priority === 'urgent' || t.priority === 'stat'), [allTasks]);
  const unreadAlerts = useMemo(() => allAlerts.filter(a => a.status === 'unread'), [allAlerts]);
  const activePlans = useMemo(() => allPlans.filter(p => p.status === 'active'), [allPlans]);

  if (tasks.loading) return <SkeletonTable rows={6} cols={5} />;

  return (
    <div className="page nursing-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Nursing Workspace</h1>
          <p className="page__subtitle">Patient care, tasks, vitals, and handover</p>
        </div>
        <div className="nursing-actions">
          <Button variant="primary" onClick={() => { setTP(''); setTD(''); setTA(''); setDlg('task'); }}>
            New Task
          </Button>
          <Button variant="ghost" onClick={() => { setVP(''); setVS(''); setVTe(''); setVHr(''); setVSB(''); setVDB(''); setVSp(''); setVPa(''); setVGc(''); setVAd(''); setDlg('vital'); }}>
            Record Vitals
          </Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Cards ──────────────────────────────────── */}
      <div className="nursing-census">
        <div className="nursing-census-card nursing-census-card--tasks">
          <span className="nursing-census-value">{pendingTasks.length}</span>
          <span className="nursing-census-label">Pending Tasks</span>
        </div>
        <div className="nursing-census-card nursing-census-card--urgent">
          <span className="nursing-census-value">{urgentTasks.length}</span>
          <span className="nursing-census-label">Urgent / STAT</span>
        </div>
        <div className="nursing-census-card nursing-census-card--vitals">
          <span className="nursing-census-value">{allVitals.length}</span>
          <span className="nursing-census-label">Vitals Recorded</span>
        </div>
        <div className="nursing-census-card nursing-census-card--plans">
          <span className="nursing-census-value">{activePlans.length}</span>
          <span className="nursing-census-label">Active Care Plans</span>
        </div>
        <div className="nursing-census-card nursing-census-card--alerts">
          <span className="nursing-census-value" style={{ color: unreadAlerts.length > 0 ? '#ef4444' : undefined }}>
            {unreadAlerts.length}
          </span>
          <span className="nursing-census-label">Unread Alerts</span>
        </div>
      </div>

      {/* ── Tab Navigation ────────────────────────────────── */}
      <div className="nursing-tabs">
        <button
          className={`nursing-tab ${activeTab === 'tasks' ? 'nursing-tab--active' : ''}`}
          onClick={() => setActiveTab('tasks')}
        >
          Tasks {pendingTasks.length > 0 && <span className="nursing-tab-badge">{pendingTasks.length}</span>}
        </button>
        <button
          className={`nursing-tab ${activeTab === 'vitals' ? 'nursing-tab--active' : ''}`}
          onClick={() => setActiveTab('vitals')}
        >
          Vitals
        </button>
        <button
          className={`nursing-tab ${activeTab === 'careplans' ? 'nursing-tab--active' : ''}`}
          onClick={() => setActiveTab('careplans')}
        >
          Care Plans
        </button>
        <button
          className={`nursing-tab ${activeTab === 'handover' ? 'nursing-tab--active' : ''}`}
          onClick={() => setActiveTab('handover')}
        >
          Handover
        </button>
        <button
          className={`nursing-tab ${activeTab === 'alerts' ? 'nursing-tab--active' : ''}`}
          onClick={() => setActiveTab('alerts')}
        >
          Alerts {unreadAlerts.length > 0 && <span className="nursing-tab-badge nursing-tab-badge--danger">{unreadAlerts.length}</span>}
        </button>
      </div>

      {/* ── Tasks Tab ─────────────────────────────────────── */}
      {activeTab === 'tasks' && (
        <Card className="nursing-section-card">
          {allTasks.length === 0 ? (
            <EmptyState title="No tasks" body="Create a nursing task to begin." />
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Type</th>
                  <th>Description</th>
                  <th>Patient</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Due</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {allTasks.map(t => (
                  <tr key={t.id}>
                    <td>{TASK_TYPES.find(x => x.value === t.taskType)?.label ?? t.taskType}</td>
                    <td className="nursing-desc">{t.description}</td>
                    <td>{t.patientId.slice(0, 8)}...</td>
                    <td>
                      <span className="nursing-priority" style={{
                        color: t.priority === 'stat' ? '#ef4444' : t.priority === 'urgent' ? '#f59e0b' : '#6b7280',
                      }}>
                        {t.priority === 'stat' ? '⚡ STAT' : t.priority === 'urgent' ? '⚠ Urgent' : '○ Routine'}
                      </span>
                    </td>
                    <td>
                      <span className="nursing-status" style={{ color: STATUS_COLORS[t.status] ?? '#6b7280' }}>
                        {t.status.replace('_', ' ')}
                      </span>
                    </td>
                    <td className="nursing-time">{t.dueAt ? new Date(t.dueAt).toLocaleString() : '—'}</td>
                    <td>
                      {t.status !== 'completed' && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => void go(() => nursingApi.completeTask(t.id).then(() => tasks.refresh()))}
                          loading={busy}
                        >
                          Complete
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Vitals Tab ────────────────────────────────────── */}
      {activeTab === 'vitals' && (
        <Card className="nursing-section-card">
          {allVitals.length === 0 ? (
            <EmptyState title="No vitals recorded" body="Record vitals for a patient to begin." />
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Patient</th>
                  <th>Temp °C</th>
                  <th>HR bpm</th>
                  <th>BP</th>
                  <th>SpO₂ %</th>
                  <th>Pain</th>
                  <th>Recorded</th>
                </tr>
              </thead>
              <tbody>
                {allVitals.map(v => (
                  <tr key={v.id}>
                    <td>{v.patientId.slice(0, 8)}...</td>
                    <td>{v.temperatureCelsius ?? '—'}</td>
                    <td>{v.heartRateBpm ?? '—'}</td>
                    <td>{v.systolicBp && v.diastolicBp ? `${v.systolicBp}/${v.diastolicBp}` : '—'}</td>
                    <td>{v.spo2Percent ?? '—'}</td>
                    <td>{v.painScore ?? '—'}</td>
                    <td className="nursing-time">{new Date(v.observedAt).toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Care Plans Tab ────────────────────────────────── */}
      {activeTab === 'careplans' && (
        <Card className="nursing-section-card">
          <div className="nursing-section-header">
            <h3>Active Care Plans</h3>
            <Button variant="ghost" size="sm" onClick={() => { setCP(''); setCS(''); setCDi(''); setCGo(''); setCIn(''); setDlg('careplan'); }}>
              New Plan
            </Button>
          </div>
          {allPlans.length === 0 ? (
            <EmptyState title="No care plans" body="Create a care plan for a patient." />
          ) : (
            <div className="nursing-plan-list">
              {allPlans.map(p => (
                <div key={p.id} className="nursing-plan-item">
                  <div className="nursing-plan-header">
                    <span className="nursing-plan-patient">{p.patientId.slice(0, 8)}...</span>
                    <span className={`nursing-plan-status nursing-plan-status--${p.status}`}>
                      {p.status}
                    </span>
                  </div>
                  <p className="nursing-plan-diagnosis">{p.diagnosis}</p>
                  <p className="nursing-plan-goals"><strong>Goals:</strong> {p.goals}</p>
                  <p className="nursing-plan-interventions"><strong>Interventions:</strong> {p.interventions}</p>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Handover Tab ──────────────────────────────────── */}
      {activeTab === 'handover' && (
        <Card className="nursing-section-card">
          <div className="nursing-section-header">
            <h3>Shift Handovers</h3>
            <Button variant="ghost" size="sm" onClick={() => { setHO(''); setHI(''); setHSu(''); setHCr(''); setDlg('handover'); }}>
              New Handover
            </Button>
          </div>
          {allHandovers.length === 0 ? (
            <EmptyState title="No handovers" body="Record a shift handover." />
          ) : (
            <div className="nursing-handover-list">
              {allHandovers.map(h => (
                <div key={h.id} className="nursing-handover-item">
                  <div className="nursing-handover-header">
                    <span className="nursing-handover-shift">{SHIFTS.find(s => s.value === h.shift)?.label ?? h.shift}</span>
                    <span className="nursing-handover-date">{h.handoverDate}</span>
                    <span className={`nursing-handover-status nursing-handover-status--${h.status}`}>
                      {h.status}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Alerts Tab ────────────────────────────────────── */}
      {activeTab === 'alerts' && (
        <Card className="nursing-section-card">
          <div className="nursing-section-header">
            <h3>Patient Alerts</h3>
            <Button variant="ghost" size="sm" onClick={() => { setAP(''); setATo(''); setAM(''); setDlg('alert'); }}>
              New Alert
            </Button>
          </div>
          {allAlerts.length === 0 ? (
            <EmptyState title="No alerts" body="All clear — no active alerts." />
          ) : (
            <div className="nursing-alert-list">
              {allAlerts.map(a => (
                <div key={a.id} className="nursing-alert-item" style={{ borderLeftColor: ALERT_COLORS[a.severity] ?? '#6b7280' }}>
                  <div className="nursing-alert-header">
                    <span className="nursing-alert-type">{ALERT_TYPES.find(t => t.value === a.alertType)?.label ?? a.alertType}</span>
                    <span className="nursing-alert-severity" style={{ color: ALERT_COLORS[a.severity] }}>
                      {a.severity}
                    </span>
                    <span className="nursing-alert-status">{a.status}</span>
                  </div>
                  {a.message && <p className="nursing-alert-message">{a.message}</p>}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}
      {dlg === 'task' && (
        <Dialog open onClose={() => setDlg(null)} title="New Nursing Task" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => void go(() =>
              nursingApi.createTask({
                patientId: tP,
                taskType: tT,
                description: tD.trim(),
                priority: tPr,
                assignedTo: tA.trim() || undefined,
                dueAt: tDu || undefined,
              }).then(() => { setDlg(null); return tasks.refresh(); })
            )} loading={busy}>Create Task</Button>
          </>
        }>
          <Input label="Patient ID" value={tP} onChange={e => setTP(e.target.value)} placeholder="Patient identifier" />
          <Select label="Task Type" value={tT} onChange={e => setTT(e.target.value)}>
            {TASK_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>
          <Input label="Description" value={tD} onChange={e => setTD(e.target.value)} placeholder="Task description" />
          <Select label="Priority" value={tPr} onChange={e => setTPr(e.target.value)}>
            {PRIORITIES.map(p => <option key={p.value} value={p.value}>{p.label}</option>)}
          </Select>
          <Input label="Assigned To" value={tA} onChange={e => setTA(e.target.value)} placeholder="Staff ID (optional)" />
          <Input label="Due At" type="datetime-local" value={tDu} onChange={e => setTDu(e.target.value)} />
        </Dialog>
      )}

      {dlg === 'vital' && (
        <Dialog open onClose={() => setDlg(null)} title="Record Vitals" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => void go(() =>
              nursingApi.recordVital({
                patientId: vP,
                recordedBy: vS,
                observedAt: now(),
                temperatureCelsius: vTe ? parseFloat(vTe) : undefined,
                heartRateBpm: vHr ? parseInt(vHr, 10) : undefined,
                systolicBp: vSB ? parseInt(vSB, 10) : undefined,
                diastolicBp: vDB ? parseInt(vDB, 10) : undefined,
                spo2Percent: vSp ? parseFloat(vSp) : undefined,
                painScore: vPa ? parseInt(vPa, 10) : undefined,
                gcsScore: vGc ? parseInt(vGc, 10) : undefined,
                admissionId: vAd.trim() || undefined,
              }).then(() => { setDlg(null); return vitals.refresh(); })
            )} loading={busy}>Record</Button>
          </>
        }>
          <Input label="Patient ID" value={vP} onChange={e => setVP(e.target.value)} placeholder="Patient identifier" />
          <Input label="Recorded By" value={vS} onChange={e => setVS(e.target.value)} placeholder="Staff ID" />
          <div className="nursing-vitals-grid">
            <Input label="Temperature °C" type="number" step="0.1" value={vTe} onChange={e => setVTe(e.target.value)} placeholder="36.5" />
            <Input label="Heart Rate bpm" type="number" value={vHr} onChange={e => setVHr(e.target.value)} placeholder="72" />
            <Input label="Systolic BP" type="number" value={vSB} onChange={e => setVSB(e.target.value)} placeholder="120" />
            <Input label="Diastolic BP" type="number" value={vDB} onChange={e => setVDB(e.target.value)} placeholder="80" />
            <Input label="SpO₂ %" type="number" value={vSp} onChange={e => setVSp(e.target.value)} placeholder="98" />
            <Input label="Pain Score (0-10)" type="number" min="0" max="10" value={vPa} onChange={e => setVPa(e.target.value)} placeholder="0" />
            <Input label="GCS Score" type="number" min="3" max="15" value={vGc} onChange={e => setVGc(e.target.value)} placeholder="15" />
            <Input label="Admission ID" value={vAd} onChange={e => setVAd(e.target.value)} placeholder="Optional" />
          </div>
        </Dialog>
      )}

      {dlg === 'careplan' && (
        <Dialog open onClose={() => setDlg(null)} title="New Care Plan" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => void go(() =>
              nursingApi.createCarePlan({
                patientId: cP,
                createdBy: cS,
                diagnosis: cDi,
                goals: cGo,
                interventions: cIn,
                effectiveFrom: now(),
              }).then(() => { setDlg(null); return plans.refresh(); })
            )} loading={busy}>Create Plan</Button>
          </>
        }>
          <Input label="Patient ID" value={cP} onChange={e => setCP(e.target.value)} placeholder="Patient identifier" />
          <Input label="Created By" value={cS} onChange={e => setCS(e.target.value)} placeholder="Staff ID" />
          <Input label="Diagnosis" value={cDi} onChange={e => setCDi(e.target.value)} placeholder="Primary diagnosis" />
          <Input label="Goals" value={cGo} onChange={e => setCGo(e.target.value)} placeholder="Care goals" />
          <Input label="Interventions" value={cIn} onChange={e => setCIn(e.target.value)} placeholder="Planned interventions" />
        </Dialog>
      )}

      {dlg === 'handover' && (
        <Dialog open onClose={() => setDlg(null)} title="Shift Handover" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => void go(() =>
              nursingApi.createHandover({
                outgoingStaffId: hO,
                incomingStaffId: hI,
                shift: hSh,
                handoverDate: hDa,
                patientSummaries: hSu,
                criticalItems: hCr || undefined,
                pendingTasks: hPt || undefined,
              }).then(() => { setDlg(null); return handovers.refresh(); })
            )} loading={busy}>Submit Handover</Button>
          </>
        }>
          <Input label="Outgoing Nurse ID" value={hO} onChange={e => setHO(e.target.value)} placeholder="Nurse ending shift" />
          <Input label="Incoming Nurse ID" value={hI} onChange={e => setHI(e.target.value)} placeholder="Nurse starting shift" />
          <Select label="Shift" value={hSh} onChange={e => setHSh(e.target.value)}>
            {SHIFTS.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
          </Select>
          <Input label="Handover Date" type="date" value={hDa} onChange={e => setHDa(e.target.value)} />
          <Input label="Patient Summaries" value={hSu} onChange={e => setHSu(e.target.value)} placeholder="Patient summaries for this shift" />
          <Input label="Critical Concerns" value={hCr} onChange={e => setHCr(e.target.value)} placeholder="Any critical issues" />
          <Input label="Pending Tasks" value={hPt} onChange={e => setHPt(e.target.value)} placeholder="Tasks to hand off" />
        </Dialog>
      )}

      {dlg === 'alert' && (
        <Dialog open onClose={() => setDlg(null)} title="New Alert" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => void go(() =>
              nursingApi.createAlert({
                patientId: aP,
                alertTo: aTo,
                alertType: aTy,
                severity: aSe,
                message: aM.trim() || 'Alert created',
              }).then(() => { setDlg(null); return alertsData.refresh(); })
            )} loading={busy}>Create Alert</Button>
          </>
        }>
          <Input label="Patient ID" value={aP} onChange={e => setAP(e.target.value)} placeholder="Patient identifier" />
          <Input label="Target Staff ID" value={aTo} onChange={e => setATo(e.target.value)} placeholder="Staff to notify" />
          <Select label="Alert Type" value={aTy} onChange={e => setATy(e.target.value)}>
            {ALERT_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>
          <Select label="Severity" value={aSe} onChange={e => setASe(e.target.value)}>
            {ALERT_SEVERITIES.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
          </Select>
          <Input label="Message" value={aM} onChange={e => setAM(e.target.value)} placeholder="Alert message" />
        </Dialog>
      )}
    </div>
  );
}
