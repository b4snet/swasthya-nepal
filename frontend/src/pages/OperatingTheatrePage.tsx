import { useCallback, useMemo, useState } from 'react';
import { otApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import './ot.css';

/* ── Constants ───────────────────────────────────────────────────── */

const PRIORITY_OPTIONS = [
  { value: 'routine', label: 'Routine', color: '#6b7280' },
  { value: 'urgent', label: 'Urgent', color: '#f59e0b' },
  { value: 'emergency', label: 'Emergency', color: '#ef4444' },
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

const TEAM_ROLES = [
  { value: 'surgeon', label: 'Surgeon' },
  { value: 'assistant', label: 'Assistant Surgeon' },
  { value: 'anesthetist', label: 'Anesthetist' },
  { value: 'nurse', label: 'Scrub Nurse' },
  { value: 'circulator', label: 'Circulating Nurse' },
  { value: 'tech', label: 'Surgical Tech' },
];

const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  requested: { label: 'Requested', color: '#f59e0b', bg: '#fef3c7' },
  scheduled: { label: 'Scheduled', color: '#3b82f6', bg: '#dbeafe' },
  in_progress: { label: 'In Progress', color: '#ef4444', bg: '#fee2e2' },
  completed: { label: 'Completed', color: '#10b981', bg: '#ecfdf5' },
  cancelled: { label: 'Cancelled', color: '#6b7280', bg: '#f3f4f6' },
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  closed: { label: 'Closed', color: '#6b7280', bg: '#f3f4f6' },
};

const THEATRE_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  inactive: { label: 'Inactive', color: '#6b7280', bg: '#f3f4f6' },
  in_use: { label: 'In Use', color: '#ef4444', bg: '#fee2e2' },
  cleaning: { label: 'Cleaning', color: '#3b82f6', bg: '#dbeafe' },
  maintenance: { label: 'Maintenance', color: '#f59e0b', bg: '#fef3c7' },
};

/* ── Main Component ──────────────────────────────────────────────── */

export function OperatingTheatrePage() {
  const theatres = useFetch(() => otApi.theatres(), ['ot-theatres']);
  const requests = useFetch(() => otApi.procedureRequests(), ['ot-requests']);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'theatre' | 'worklist' | 'active' | 'completed'>('worklist');
  const [dlg, setDlg] = useState<string | null>(null);
  const [detail, setDetail] = useState<Record<string, unknown> | null>(null);
  const [selectedReqId, setSelectedReqId] = useState<string | null>(null);

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

  const loadDetail = useCallback(async (id: string) => {
    const d = await withError(() => otApi.showProcedure(id));
    if (d) { setDetail(d as unknown as Record<string, unknown>); setDlg('detail'); }
  }, []);

  const allRequests = requests.data ?? [];
  const pending = useMemo(() => allRequests.filter(r => r.status === 'requested'), [allRequests]);
  const scheduled = useMemo(() => allRequests.filter(r => r.status === 'scheduled'), [allRequests]);
  const active = useMemo(() => allRequests.filter(r => r.status === 'in_progress'), [allRequests]);
  const completed = useMemo(() => allRequests.filter(r => r.status === 'completed'), [allRequests]);
  const cancelled = useMemo(() => allRequests.filter(r => r.status === 'cancelled'), [allRequests]);

  if (requests.loading) return <SkeletonTable rows={6} cols={5} />;

  return (
    <div className="page ot-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Operating Theatre</h1>
          <p className="page__subtitle">Perioperative scheduling, workflow, and documentation</p>
        </div>
        <div className="ot-actions">
          <Button variant="primary" onClick={() => { setRPatient(''); setRProc(''); setDlg('request'); }}>
            New Procedure Request
          </Button>
          <Button variant="ghost" onClick={() => { setTCode(''); setTName(''); setDlg('theatre'); }}>
            Add Theatre
          </Button>
          <Button variant="ghost" onClick={() => void refresh()}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="ot-census">
        <div className="ot-census-card ot-census-card--theatres">
          <span className="ot-census-value">{theatres.data?.length ?? 0}</span>
          <span className="ot-census-label">Theatres</span>
        </div>
        <div className="ot-census-card ot-census-card--pending">
          <span className="ot-census-value">{pending.length}</span>
          <span className="ot-census-label">Pending</span>
        </div>
        <div className="ot-census-card ot-census-card--scheduled">
          <span className="ot-census-value">{scheduled.length}</span>
          <span className="ot-census-label">Scheduled</span>
        </div>
        <div className="ot-census-card ot-census-card--active">
          <span className="ot-census-value">{active.length}</span>
          <span className="ot-census-label">In Progress</span>
        </div>
        <div className="ot-census-card ot-census-card--completed">
          <span className="ot-census-value">{completed.length}</span>
          <span className="ot-census-label">Completed</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="ot-tabs">
        <button className={`ot-tab ${activeTab === 'worklist' ? 'ot-tab--active' : ''}`} onClick={() => setActiveTab('worklist')}>
          Day Surgery
        </button>
        <button className={`ot-tab ${activeTab === 'theatre' ? 'ot-tab--active' : ''}`} onClick={() => setActiveTab('theatre')}>
          Theatre Board
        </button>
        <button className={`ot-tab ${activeTab === 'active' ? 'ot-tab--active' : ''}`} onClick={() => setActiveTab('active')}>
          Active Cases
        </button>
        <button className={`ot-tab ${activeTab === 'completed' ? 'ot-tab--active' : ''}`} onClick={() => setActiveTab('completed')}>
          Completed
        </button>
      </div>

      {/* ── Day Surgery Worklist ──────────────────────────── */}
      {activeTab === 'worklist' && (
        <Card className="ot-section-card">
          <div className="ot-section-header">
            <h3>Today's Cases</h3>
            <span className="ot-badge">{pending.length + scheduled.length}</span>
          </div>
          {pending.length + scheduled.length === 0 ? (
            <EmptyState title="No cases scheduled" body="Create a procedure request to begin." />
          ) : (
            <div className="ot-case-list">
              {[...pending, ...scheduled].map(req => {
                const cfg = STATUS_CONFIG[req.status] ?? STATUS_CONFIG.requested;
                const prio = PRIORITY_OPTIONS.find(p => p.value === req.priority);
                return (
                  <div
                    key={req.id}
                    className={`ot-case-row ${selectedReqId === req.id ? 'ot-case-row--selected' : ''}`}
                    onClick={() => setSelectedReqId(selectedReqId === req.id ? null : req.id)}
                    role="button"
                    tabIndex={0}
                    onKeyDown={(e) => { if (e.key === 'Enter') setSelectedReqId(selectedReqId === req.id ? null : req.id); }}
                  >
                    <div className="ot-case-time">
                      {req.scheduledAt ? (
                        <>
                          <span className="ot-case-date">{new Date(req.scheduledAt).toLocaleDateString()}</span>
                          <span className="ot-case-hour">{new Date(req.scheduledAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                        </>
                      ) : (
                        <span className="ot-case-hour ot-case-hour--unscheduled">Not scheduled</span>
                      )}
                    </div>
                    <div className="ot-case-info">
                      <span className="ot-case-procedure">{req.procedureName}</span>
                      <span className="ot-case-patient">{req.patientId.slice(0, 8)}...</span>
                    </div>
                    <div className="ot-case-meta">
                      {prio && (
                        <span className="ot-case-priority" style={{ color: prio.color, backgroundColor: prio.color + '15' }}>
                          {prio.label}
                        </span>
                      )}
                      {req.durationMinutes && (
                        <span className="ot-case-duration">{req.durationMinutes}min</span>
                      )}
                    </div>
                    <span className="ot-case-status" style={{ color: cfg.color, backgroundColor: cfg.bg }}>
                      {cfg.label}
                    </span>
                    <div className="ot-case-actions" onClick={(e) => e.stopPropagation()}>
                      {req.status === 'requested' && (
                        <Button variant="secondary" size="sm" onClick={() => { setSTheatre(''); setSDate(''); setSDur('60'); setDlg('schedule'); setSelectedReqId(req.id); }}>
                          Schedule
                        </Button>
                      )}
                      {req.status === 'scheduled' && (
                        <Button variant="primary" size="sm" onClick={() => { setStartSurgeon(''); setDlg('start'); setSelectedReqId(req.id); }}>
                          Start
                        </Button>
                      )}
                      {req.status !== 'requested' && (
                        <Button variant="ghost" size="sm" onClick={() => void loadDetail(req.id)}>
                          View
                        </Button>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* ── Theatre Board ──────────────────────────────────── */}
      {activeTab === 'theatre' && (
        <div className="ot-theatre-grid">
          {(theatres.data ?? []).length === 0 ? (
            <EmptyState title="No theatres configured" body="Add an operating theatre to begin." />
          ) : (
            (theatres.data ?? []).map(t => {
              const cfg = THEATRE_STATUS[t.status] ?? THEATRE_STATUS.inactive;
              const theatreCases = scheduled.filter(r => r.theatreId === t.id);
              const activeCase = active.find(r => r.theatreId === t.id);
              return (
                <Card key={t.id} className="ot-theatre-card">
                  <div className="ot-theatre-header">
                    <div>
                      <h3 className="ot-theatre-name">{t.name}</h3>
                      <span className="ot-theatre-code">{t.code}</span>
                    </div>
                    <span className="ot-theatre-status" style={{ color: cfg.color, backgroundColor: cfg.bg }}>
                      {cfg.label}
                    </span>
                  </div>
                  {activeCase ? (
                    <div className="ot-theatre-active">
                      <span className="ot-theatre-active-label">Now in progress</span>
                      <span className="ot-theatre-active-procedure">{activeCase.procedureName}</span>
                      <span className="ot-theatre-active-patient">{activeCase.patientId.slice(0, 8)}...</span>
                      <Button variant="ghost" size="sm" onClick={() => void loadDetail(activeCase.id)}>View</Button>
                    </div>
                  ) : theatreCases.length > 0 ? (
                    <div className="ot-theatre-queue">
                      {theatreCases.map(c => (
                        <div key={c.id} className="ot-theatre-queue-item">
                          <span className="ot-theatre-queue-time">
                            {c.scheduledAt ? new Date(c.scheduledAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'}
                          </span>
                          <span className="ot-theatre-queue-proc">{c.procedureName}</span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="ot-theatre-empty">No cases scheduled</div>
                  )}
                </Card>
              );
            })
          )}
        </div>
      )}

      {/* ── Active Cases ──────────────────────────────────── */}
      {activeTab === 'active' && (
        <Card className="ot-section-card">
          <div className="ot-section-header">
            <h3>Active Procedures</h3>
          </div>
          {active.length === 0 ? (
            <EmptyState title="No active procedures" body="Start a scheduled procedure to see it here." />
          ) : (
            <div className="ot-case-list">
              {active.map(req => (
                <div key={req.id} className="ot-case-row ot-case-row--active">
                  <div className="ot-case-info">
                    <span className="ot-case-procedure">{req.procedureName}</span>
                    <span className="ot-case-patient">{req.patientId.slice(0, 8)}...</span>
                  </div>
                  <div className="ot-case-actions">
                    <Button variant="primary" size="sm" onClick={() => void loadDetail(req.id)}>View Case</Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Completed ─────────────────────────────────────── */}
      {activeTab === 'completed' && (
        <Card className="ot-section-card">
          <div className="ot-section-header">
            <h3>Completed / Cancelled</h3>
            <span className="ot-badge">{completed.length + cancelled.length}</span>
          </div>
          {[...completed, ...cancelled].length === 0 ? (
            <EmptyState title="No completed cases" body="Cases will appear here after completion." />
          ) : (
            <div className="ot-case-list">
              {[...completed, ...cancelled].map(req => {
                const cfg = STATUS_CONFIG[req.status] ?? STATUS_CONFIG.completed;
                return (
                  <div key={req.id} className="ot-case-row">
                    <div className="ot-case-info">
                      <span className="ot-case-procedure">{req.procedureName}</span>
                      <span className="ot-case-patient">{req.patientId.slice(0, 8)}...</span>
                    </div>
                    <span className="ot-case-status" style={{ color: cfg.color, backgroundColor: cfg.bg }}>
                      {cfg.label}
                    </span>
                    <Button variant="ghost" size="sm" onClick={() => void loadDetail(req.id)}>View</Button>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* Create Theatre */}
      {dlg === 'theatre' && (
        <Dialog open onClose={() => setDlg(null)} title="Add Operating Theatre" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!tCode.trim() || !tName.trim()) return;
              await withError(async () => { await otApi.createTheatre({ code: tCode.trim(), name: tName.trim() }); setDlg(null); refresh(); });
            }} loading={busy}>Create Theatre</Button>
          </>
        }>
          <Input label="Theatre Code" value={tCode} onChange={e => setTCode(e.target.value)} placeholder="e.g. OT-01" />
          <Input label="Theatre Name" value={tName} onChange={e => setTName(e.target.value)} placeholder="e.g. Main Operating Theatre 1" />
        </Dialog>
      )}

      {/* New Procedure Request */}
      {dlg === 'request' && (
        <Dialog open onClose={() => setDlg(null)} title="New Procedure Request" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!rPatient.trim() || !rProc.trim()) return;
              await withError(async () => { await otApi.createProcedureRequest({ patientId: rPatient.trim(), procedureName: rProc.trim(), priority: rPrio }); setDlg(null); refresh(); });
            }} loading={busy}>Submit Request</Button>
          </>
        }>
          <Input label="Patient ID" value={rPatient} onChange={e => setRPatient(e.target.value)} placeholder="Patient identifier" />
          <Input label="Procedure" value={rProc} onChange={e => setRProc(e.target.value)} placeholder="e.g. Appendectomy, Laparoscopic Cholecystectomy" />
          <Select label="Priority" value={rPrio} onChange={e => setRPrio(e.target.value)}>
            {PRIORITY_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
        </Dialog>
      )}

      {/* Schedule */}
      {dlg === 'schedule' && (
        <Dialog open onClose={() => setDlg(null)} title="Schedule Procedure" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!selectedReqId || !sTheatre || !sDate) return;
              await withError(async () => {
                await otApi.schedule(selectedReqId, { theatreId: sTheatre, scheduledAt: new Date(sDate).toISOString(), durationMinutes: parseInt(sDur) || 60 });
                setDlg(null); refresh();
              });
            }} loading={busy}>Confirm Schedule</Button>
          </>
        }>
          <Select label="Operating Theatre" value={sTheatre} onChange={e => setSTheatre(e.target.value)}>
            <option value="">Select theatre...</option>
            {(theatres.data ?? []).filter(t => t.status === 'active').map(t => (
              <option key={t.id} value={t.id}>{t.code} — {t.name}</option>
            ))}
          </Select>
          <Input label="Date & Time" type="datetime-local" value={sDate} onChange={e => setSDate(e.target.value)} />
          <Input label="Estimated Duration (minutes)" type="number" value={sDur} onChange={e => setSDur(e.target.value)} placeholder="60" />
        </Dialog>
      )}

      {/* Start Procedure */}
      {dlg === 'start' && (
        <Dialog open onClose={() => setDlg(null)} title="Start Procedure" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!selectedReqId || !startSurgeon.trim()) return;
              await withError(async () => {
                await otApi.start(selectedReqId, { surgeonStaffId: startSurgeon.trim() });
                setDlg(null); refresh();
              });
            }} loading={busy}>Start Procedure</Button>
          </>
        }>
          <Input label="Surgeon Staff ID" value={startSurgeon} onChange={e => setStartSurgeon(e.target.value)} placeholder="Lead surgeon staff identifier" />
          <Alert tone="info">This will create a procedure record with a safety checklist and begin the intraoperative workflow.</Alert>
        </Dialog>
      )}

      {/* Procedure Detail */}
      {dlg === 'detail' && detail && (
        <Dialog open onClose={() => { setDlg(null); setDetail(null); }} title="Procedure Record" footer={
          <>
            <Button variant="ghost" onClick={() => { setDlg(null); setDetail(null); }}>Close</Button>
            {(detail.status as string) === 'in_progress' && (
              <Button onClick={async () => {
                await withError(async () => { await otApi.close(detail.id as string); setDlg(null); setDetail(null); refresh(); });
              }} loading={busy}>Close Case</Button>
            )}
            {(detail.status as string) === 'completed' && (
              <Button onClick={async () => {
                await withError(async () => { await otApi.admitToRecovery(detail.id as string); loadDetail(detail.id as string); });
              }} loading={busy}>Admit to Recovery</Button>
            )}
          </>
        }>
          <div className="ot-detail">
            {/* Status */}
            <div className="ot-detail-row">
              <span className="ot-detail-label">Status</span>
              <span className="ot-case-status" style={{ color: (STATUS_CONFIG[detail.status as string] ?? STATUS_CONFIG.requested).color, backgroundColor: (STATUS_CONFIG[detail.status as string] ?? STATUS_CONFIG.requested).bg }}>
                {detail.status as string}
              </span>
            </div>
            <div className="ot-detail-row">
              <span className="ot-detail-label">Patient</span>
              <span>{String(detail.patientId ?? '').slice(0, 12)}...</span>
            </div>

            {/* Team */}
            <h4>Surgical Team</h4>
            {(detail.team as unknown[])?.length ? (
              <div className="ot-team-list">
                {(detail.team as Record<string, unknown>[]).map(m => (
                  <div key={m.id as string} className="ot-team-item">
                    <span className="ot-team-role">{m.role as string}</span>
                    <span className="ot-team-staff">{String(m.staffId).slice(0, 8)}...</span>
                    <span className="ot-team-time">{m.timeIn ? new Date(m.timeIn as string).toLocaleTimeString() : '—'}</span>
                  </div>
                ))}
              </div>
            ) : <p className="ot-muted">No team members assigned</p>}

            {(detail.status as string) === 'in_progress' && (
              <Button variant="ghost" size="sm" onClick={() => { setTeamStaff(''); setDlg('team'); }}>Add Team Member</Button>
            )}

            {/* Safety Checklist */}
            <h4>Safety Checklist</h4>
            <div className="ot-checklist">
              {(detail.checklist as Record<string, unknown>[] ?? []).map(item => (
                <div key={item.id as string} className={`ot-checklist-item ${item.completedAt ? 'ot-checklist-item--done' : ''}`}>
                  <span className="ot-checklist-icon">{item.completedAt ? '✓' : '○'}</span>
                  <span className="ot-checklist-label">{item.label as string}</span>
                  {!item.completedAt && (detail.status as string) === 'in_progress' && (
                    <Button variant="ghost" size="sm" onClick={async () => {
                      await withError(() => otApi.completeChecklist(detail.id as string, item.id as string));
                      loadDetail(detail.id as string);
                    }}>Complete</Button>
                  )}
                </div>
              ))}
            </div>

            {/* Events */}
            <h4>Surgical Events</h4>
            {(detail.events as Record<string, unknown>[] ?? []).length > 0 ? (
              <div className="ot-event-list">
                {(detail.events as Record<string, unknown>[]).map(ev => (
                  <div key={ev.id as string} className="ot-event-item">
                    <span className="ot-event-type">{ev.eventType as string}</span>
                    <span className="ot-event-time">{new Date(ev.occurredAt as string).toLocaleTimeString()}</span>
                  </div>
                ))}
              </div>
            ) : <p className="ot-muted">No events recorded</p>}

            {(detail.status as string) === 'in_progress' && (
              <div className="ot-detail-actions">
                <Button variant="ghost" size="sm" onClick={() => { setAnesStaff(''); setDlg('anesthesia'); }}>Record Anesthesia</Button>
                <Button variant="ghost" size="sm" onClick={() => { setEvType('note'); setEvNotes(''); setDlg('event'); }}>Record Event</Button>
              </div>
            )}

            {/* Recovery */}
            {(detail.recovery as Record<string, unknown> | null) && (
              <div className="ot-recovery">
                <h4>Recovery</h4>
                <span className="ot-case-status" style={{ color: STATUS_CONFIG[(detail.recovery as Record<string, unknown>).status as string]?.color, backgroundColor: STATUS_CONFIG[(detail.recovery as Record<string, unknown>).status as string]?.bg }}>
                  {(detail.recovery as Record<string, unknown>).status as string}
                </span>
              </div>
            )}
          </div>
        </Dialog>
      )}

      {/* Team Member */}
      {dlg === 'team' && (
        <Dialog open onClose={() => setDlg('detail')} title="Add Team Member" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg('detail')}>Cancel</Button>
            <Button onClick={async () => {
              if (!detail || !teamStaff.trim()) return;
              await withError(async () => {
                await otApi.addTeamMember(detail.id as string, { staffId: teamStaff.trim(), role: teamRole });
                setDlg('detail'); setTeamStaff(''); loadDetail(detail.id as string);
              });
            }} loading={busy}>Add Member</Button>
          </>
        }>
          <Input label="Staff ID" value={teamStaff} onChange={e => setTeamStaff(e.target.value)} placeholder="Staff identifier" />
          <Select label="Role" value={teamRole} onChange={e => setTeamRole(e.target.value)}>
            {TEAM_ROLES.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
        </Dialog>
      )}

      {/* Anesthesia */}
      {dlg === 'anesthesia' && (
        <Dialog open onClose={() => setDlg('detail')} title="Record Anesthesia" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg('detail')}>Cancel</Button>
            <Button onClick={async () => {
              if (!detail || !anesStaff.trim()) return;
              await withError(async () => {
                await otApi.startAnesthesia(detail.id as string, { anesthetistStaffId: anesStaff.trim(), anesthesiaType: anesType });
                setDlg('detail'); setAnesStaff(''); loadDetail(detail.id as string);
              });
            }} loading={busy}>Start Anesthesia</Button>
          </>
        }>
          <Input label="Anesthetist Staff ID" value={anesStaff} onChange={e => setAnesStaff(e.target.value)} placeholder="Staff identifier" />
          <Select label="Anesthesia Type" value={anesType} onChange={e => setAnesType(e.target.value)}>
            {ANESTHESIA_TYPES.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
        </Dialog>
      )}

      {/* Surgical Event */}
      {dlg === 'event' && (
        <Dialog open onClose={() => setDlg('detail')} title="Record Surgical Event" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg('detail')}>Cancel</Button>
            <Button onClick={async () => {
              if (!detail) return;
              await withError(async () => {
                await otApi.recordEvent(detail.id as string, { eventType: evType, notes: evNotes.trim() || undefined });
                setDlg('detail'); setEvNotes(''); loadDetail(detail.id as string);
              });
            }} loading={busy}>Record Event</Button>
          </>
        }>
          <Select label="Event Type" value={evType} onChange={e => setEvType(e.target.value)}>
            {EVENT_TYPES.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
          <Input label="Notes" value={evNotes} onChange={e => setEvNotes(e.target.value)} placeholder="Optional clinical notes" />
        </Dialog>
      )}
    </div>
  );
}
