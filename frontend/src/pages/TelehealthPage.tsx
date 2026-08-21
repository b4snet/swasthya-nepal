import { useState, useEffect } from 'react';
import { telehealthApi } from '../api/endpoints';
import { Alert, Button, Dialog, EmptyState, ErrorState, Input, StatusChip } from '../components/ui';
import { ApiError } from '../api/client';
import './telehealth.css';

interface Teleconsult {
  id: string;
  appointmentId: string;
  patientId: string;
  providerStaffId: string;
  status: string;
  scheduledAt: string;
  startsAt: string | null;
  endsAt: string | null;
  fallbackMode: string | null;
  lockVersion: number;
  patient?: { id: string; fullName: string; mrn: string } | null;
  provider?: { id: string; fullName: string } | null;
  videoSessions?: Array<Record<string, unknown>>;
}

const STATUS_COLORS: Record<string, string> = {
  scheduled: '#3b82f6',
  ready: '#f59e0b',
  in_progress: '#10b981',
  completed: '#64748b',
  cancelled: '#ef4444',
  failed: '#ef4444',
};

const STATUS_LABELS: Record<string, string> = {
  scheduled: 'Scheduled',
  ready: 'Ready',
  in_progress: 'In Progress',
  completed: 'Completed',
  cancelled: 'Cancelled',
  failed: 'Failed',
};

export function TelehealthPage() {
  const [tab, setTab] = useState<'waiting' | 'active' | 'completed'>('waiting');
  const [consults, setConsults] = useState<Teleconsult[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<Teleconsult | null>(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [fallbackDlg, setFallbackDlg] = useState(false);
  const [fallbackMode, setFallbackMode] = useState('phone');
  const [fallbackReason, setFallbackReason] = useState('');

  const load = async () => {
    setLoading(true);
    try {
      const data = await telehealthApi.list();
      setConsults(data as unknown as Teleconsult[]);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to load teleconsults');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const waiting = consults.filter(c => ['scheduled', 'ready'].includes(c.status));
  const active = consults.filter(c => c.status === 'in_progress');
  const completed = consults.filter(c => ['completed', 'cancelled', 'failed'].includes(c.status));

  const currentList = tab === 'waiting' ? waiting : tab === 'active' ? active : completed;

  const doAction = async (action: () => Promise<unknown>) => {
    setActionLoading(true);
    setActionError(null);
    try {
      await action();
      void load();
      setSelected(null);
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : 'Action failed');
    } finally {
      setActionLoading(false);
    }
  };

  if (loading) return <div className="tele"><div className="tele__header"><h2>Telehealth</h2></div><div className="tele__loading">Loading…</div></div>;
  if (error) return <ErrorState error={error} onRetry={() => void load()} />;

  return (
    <div className="tele">
      <div className="tele__header">
        <div>
          <h2>Telehealth &amp; Video Consultation</h2>
          <p className="tele__subtitle">Virtual consultations with integrated waiting room, video sessions, and clinical documentation.</p>
        </div>
      </div>

      {/* Tabs */}
      <div className="tele__tabs">
        <button className={`tele__tab ${tab === 'waiting' ? 'tele__tab--active' : ''}`} onClick={() => setTab('waiting')}>
          Waiting Room <span className="tele__tab-count">{waiting.length}</span>
        </button>
        <button className={`tele__tab ${tab === 'active' ? 'tele__tab--active' : ''}`} onClick={() => setTab('active')}>
          Active <span className="tele__tab-count">{active.length}</span>
        </button>
        <button className={`tele__tab ${tab === 'completed' ? 'tele__tab--active' : ''}`} onClick={() => setTab('completed')}>
          Completed
        </button>
      </div>

      {/* Consult list */}
      {currentList.length === 0 ? (
        <EmptyState
          title={tab === 'waiting' ? 'Waiting room is empty' : tab === 'active' ? 'No active consultations' : 'No completed consultations'}
          body={tab === 'waiting' ? 'Patients will appear here when their teleconsult appointment time arrives.' : ''}
        />
      ) : (
        <div className="tele__list">
          {currentList.map(c => (
            <div key={c.id} className={`tele__card tele__card--${c.status}`} onClick={() => setSelected(c)}>
              <div className="tele__card-left">
                <div className="tele__card-avatar" style={{ background: STATUS_COLORS[c.status] || '#64748b' }}>
                  {c.patient?.fullName?.charAt(0) ?? '?'}
                </div>
                <div className="tele__card-info">
                  <span className="tele__card-name">{c.patient?.fullName ?? 'Unknown Patient'}</span>
                  <span className="tele__card-mrn">{c.patient?.mrn ?? '—'}</span>
                  <span className="tele__card-provider">Dr. {c.provider?.fullName ?? '—'}</span>
                </div>
              </div>
              <div className="tele__card-right">
                <StatusChip tone={c.status === 'in_progress' ? 'success' : c.status === 'failed' ? 'danger' : 'neutral'} label={STATUS_LABELS[c.status] ?? c.status} />
                <span className="tele__card-time">{new Date(c.scheduledAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Detail / Action dialog */}
      {selected && (
        <Dialog open={true} onClose={() => { setSelected(null); setActionError(null); }} title={`Teleconsult — ${selected.patient?.fullName ?? ''}`} footer={
          <>
            <Button variant="ghost" onClick={() => { setSelected(null); setActionError(null); }}>Close</Button>
            {selected.status === 'scheduled' && (
              <Button onClick={() => void doAction(() => telehealthApi.markReady(selected.id))} loading={actionLoading}>Mark Ready</Button>
            )}
            {selected.status === 'ready' && (
              <Button onClick={() => void doAction(() => telehealthApi.start(selected.id))} loading={actionLoading}>Start Consult</Button>
            )}
            {selected.status === 'in_progress' && (
              <>
                <Button variant="secondary" onClick={() => void doAction(() => telehealthApi.openVideoSession(selected.id))} loading={actionLoading}>Open Video</Button>
                <Button onClick={() => void doAction(() => telehealthApi.complete(selected.id))} loading={actionLoading}>Complete</Button>
                <Button variant="ghost" onClick={() => setFallbackDlg(true)}>Report Failure</Button>
              </>
            )}
            {['scheduled', 'ready'].includes(selected.status) && (
              <Button variant="ghost" onClick={() => void doAction(() => telehealthApi.cancel(selected.id))} loading={actionLoading}>Cancel</Button>
            )}
          </>
        }>
          <div className="tele__detail">
            {actionError && <Alert tone="danger">{actionError}</Alert>}

            <div className="tele__detail-row">
              <span className="tele__detail-label">Status</span>
              <StatusChip tone={selected.status === 'in_progress' ? 'success' : 'neutral'} label={STATUS_LABELS[selected.status] ?? selected.status} />
            </div>
            <div className="tele__detail-row">
              <span className="tele__detail-label">Patient</span>
              <span>{selected.patient?.fullName} ({selected.patient?.mrn})</span>
            </div>
            <div className="tele__detail-row">
              <span className="tele__detail-label">Provider</span>
              <span>Dr. {selected.provider?.fullName}</span>
            </div>
            <div className="tele__detail-row">
              <span className="tele__detail-label">Scheduled</span>
              <span>{new Date(selected.scheduledAt).toLocaleString()}</span>
            </div>
            {selected.startsAt && (
              <div className="tele__detail-row">
                <span className="tele__detail-label">Started</span>
                <span>{new Date(selected.startsAt).toLocaleString()}</span>
              </div>
            )}
            {selected.endsAt && (
              <div className="tele__detail-row">
                <span className="tele__detail-label">Ended</span>
                <span>{new Date(selected.endsAt).toLocaleString()}</span>
              </div>
            )}
            {selected.fallbackMode && (
              <div className="tele__detail-row">
                <span className="tele__detail-label">Fallback</span>
                <span className="tele__detail-fallback">{selected.fallbackMode}</span>
              </div>
            )}

            {/* Video sessions */}
            {selected.videoSessions && selected.videoSessions.length > 0 && (
              <div className="tele__sessions">
                <h4>Video Sessions</h4>
                {selected.videoSessions.map((s: Record<string, unknown>, i: number) => (
                  <div key={i} className="tele__session-row">
                    <StatusChip tone={String(s.status) === 'active' ? 'success' : 'neutral'} label={String(s.status ?? '')} />
                    <span>{String(s.participantType ?? '')}</span>
                    {Boolean(s.startedAt) && <span>{new Date(String(s.startedAt)).toLocaleTimeString()}</span>}
                    {Boolean(s.recordingRequested) && <span className="tele__recording-badge">Recording</span>}
                  </div>
                ))}
              </div>
            )}
          </div>
        </Dialog>
      )}

      {/* Fallback dialog */}
      {fallbackDlg && selected && (
        <Dialog open={true} onClose={() => setFallbackDlg(false)} title="Report Connectivity Failure" footer={
          <>
            <Button variant="ghost" onClick={() => setFallbackDlg(false)}>Cancel</Button>
            <Button onClick={() => void doAction(async () => {
              const lastSession = selected.videoSessions?.[selected.videoSessions.length - 1];
              if (lastSession?.id) {
                await telehealthApi.failVideoSession(String(lastSession.id), fallbackMode, fallbackReason);
              }
              setFallbackDlg(false);
            })} loading={actionLoading}>Report &amp; Fallback</Button>
          </>
        }>
          <div className="tele__fallback">
            <p className="tele__fallback-desc">Select the fallback mode and provide a reason for the connectivity failure.</p>
            <div>
              <label className="form-label">Fallback Mode</label>
              <select className="form-select" value={fallbackMode} onChange={e => setFallbackMode(e.target.value)}>
                <option value="phone">Phone Consultation</option>
                <option value="in_person">In-Person Visit</option>
                <option value="reschedule">Reschedule</option>
              </select>
            </div>
            <Input label="Reason" value={fallbackReason} onChange={e => setFallbackReason(e.target.value)} placeholder="e.g. Patient connection dropped, poor video quality" />
          </div>
        </Dialog>
      )}
    </div>
  );
}
