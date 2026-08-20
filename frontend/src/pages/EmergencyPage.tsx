import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { erApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import { formatDateTime } from '../components/ui';
import './pages.css';

interface ErQueueEntry {
  encounterId: string;
  patientId: string;
  facilityId: string;
  registeredAt: string | null;
  triageLevel: number | null;
  triageColor: string | null;
  presentingComplaint: string | null;
}

interface TriageScale {
  id: string;
  code: string;
  name: string;
  level: number;
  color: string;
  reassessmentMinutes: number;
  isDefault: boolean;
  status: string;
}

const DISPOSITION_OPTIONS = [
  { value: 'admit', label: 'Admit to IPD' },
  { value: 'transfer', label: 'Transfer to ICU/OT' },
  { value: 'home', label: 'Discharge Home' },
  { value: 'referred', label: 'Refer Out' },
  { value: 'ama', label: 'Left Against Medical Advice' },
];

const EVENT_TYPES = [
  { value: 'assessment', label: 'Initial Assessment' },
  { value: 'vitals', label: 'Vitals Recorded' },
  { value: 'medication', label: 'Medication Given' },
  { value: 'order', label: 'Order Placed' },
  { value: 'result', label: 'Result Received' },
  { value: 'consult', label: 'Consultation' },
  { value: 'procedure', label: 'Procedure Performed' },
  { value: 'note', label: 'Clinical Note' },
];

export function EmergencyPage() {
  const { organizationId, selectedFacilityId: facilityId } = useTenant();
  const [showRegDialog, setShowRegDialog] = useState(false);
  const [showTriageDialog, setTriageDialog] = useState<ErQueueEntry | null>(null);
  const [showEventDialog, setEventDialog] = useState<ErQueueEntry | null>(null);
  const [showDispDialog, setDispDialog] = useState<ErQueueEntry | null>(null);
  const [error, setError] = useState<string | null>(null);

  const queue = useFetch(() => erApi.queue(), ['er-queue']);
  const scales = useFetch(() => organizationId ? erApi.triageScales(organizationId) : Promise.resolve([]), ['er-scales', organizationId]);

  const refreshQueue = useCallback(() => { void queue.refresh(); }, [queue]);

  // Auto-refresh queue every 30s
  useEffect(() => {
    const id = setInterval(refreshQueue, 30000);
    return () => clearInterval(id);
  }, [refreshQueue]);

  if (queue.loading) return <SkeletonTable rows={6} cols={5} />;
  if (queue.error) return <EmptyState title="Emergency Department" body={queue.error.message} />;

  const entries = queue.data ?? [];

  return (
    <div className="page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Emergency Department</h1>
          <p className="page__subtitle">{entries.length} patients in queue</p>
        </div>
        <div className="er-actions">
          <Button variant="primary" onClick={() => setShowRegDialog(true)}>New Registration</Button>
          <Button variant="ghost" onClick={() => void refreshQueue()}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* Triage Queue */}
      <Card className="er-queue-card">
        <div className="er-queue-header">
          <h3>Triage Queue</h3>
          <span className="er-queue-count">{entries.length}</span>
        </div>
        {entries.length === 0 ? (
          <EmptyState title="Queue is clear" body="No patients currently in the emergency department." />
        ) : (
          <div className="er-queue-list">
            {entries.map((entry) => (
              <div key={entry.encounterId} className="er-queue-item">
                <div className="er-queue-triage">
                  {entry.triageLevel !== null ? (
                    <span className="er-triage-badge" style={{ backgroundColor: entry.triageColor ?? 'var(--gray-400)' }}>
                      {entry.triageLevel}
                    </span>
                  ) : (
                    <span className="er-triage-badge er-triage-badge--untriaged">?</span>
                  )}
                </div>
                <div className="er-queue-info">
                  <Link to={`/encounters/${entry.encounterId}`} className="er-queue-patient">
                    {entry.patientId.slice(0, 8)}...
                  </Link>
                  {entry.presentingComplaint && (
                    <span className="er-queue-complaint">{entry.presentingComplaint}</span>
                  )}
                  <span className="er-queue-time">
                    {entry.registeredAt ? formatDateTime(entry.registeredAt) : '—'}
                  </span>
                </div>
                <div className="er-queue-actions">
                  {entry.triageLevel === null && (
                    <Button variant="secondary" size="sm" onClick={() => setTriageDialog(entry)}>
                      Triage
                    </Button>
                  )}
                  <Button variant="ghost" size="sm" onClick={() => setEventDialog(entry)}>Event</Button>
                  <Button variant="ghost" size="sm" onClick={() => setDispDialog(entry)}>Disposition</Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {/* Registration Dialog */}
      <ErRegistrationDialog
        open={showRegDialog}
        facilityId={facilityId}
        onClose={() => { setShowRegDialog(false); setError(null); }}
        onRegistered={() => { setShowRegDialog(false); void refreshQueue(); }}
        onError={setError}
      />

      {/* Triage Dialog */}
      {showTriageDialog && (
        <ErTriageDialog
          open
          encounterId={showTriageDialog.encounterId}
          scales={scales.data ?? []}
          onClose={() => { setTriageDialog(null); setError(null); }}
          onTriaed={() => { setTriageDialog(null); void refreshQueue(); }}
          onError={setError}
        />
      )}

      {/* Event Dialog */}
      {showEventDialog && (
        <ErEventDialog
          open
          encounterId={showEventDialog.encounterId}
          onClose={() => { setEventDialog(null); setError(null); }}
          onRecorded={() => { setEventDialog(null); }}
          onError={setError}
        />
      )}

      {/* Disposition Dialog */}
      {showDispDialog && (
        <ErDispositionDialog
          open
          encounterId={showDispDialog.encounterId}
          onClose={() => { setDispDialog(null); setError(null); }}
          onDisposed={() => { setDispDialog(null); void refreshQueue(); }}
          onError={setError}
        />
      )}
    </div>
  );
}

/* ── Registration Dialog ─────────────────────────────────────────── */

function ErRegistrationDialog({ open, facilityId, onClose, onRegistered, onError }: {
  open: boolean;
  facilityId: string | null;
  onClose: () => void;
  onRegistered: () => void;
  onError: (msg: string) => void;
}) {
  const [patientName, setPatientName] = useState('');
  const [sex, setSex] = useState('');
  const [dob, setDob] = useState('');
  const [age, setAge] = useState('');
  const [complaint, setComplaint] = useState('');
  const [busy, setBusy] = useState(false);

  const handleSubmit = async () => {
    if (!facilityId) { onError('No facility selected.'); return; }
    setBusy(true);
    try {
      await erApi.register({
        facilityId,
        patientName: patientName.trim() || undefined,
        sex: sex || undefined,
        dateOfBirth: dob || undefined,
        estimatedAge: age ? parseInt(age, 10) : undefined,
        presentingComplaint: complaint.trim() || undefined,
      });
      onRegistered();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Registration failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Emergency Registration" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void handleSubmit()} loading={busy}>Register</Button>
      </>
    }>
      <div className="form-grid">
        <Input label="Patient Name" value={patientName} onChange={e => setPatientName(e.target.value)} placeholder="Leave blank if unidentified" />
        <Select label="Sex" value={sex} onChange={e => setSex(e.target.value)}>
          <option value="">—</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
          <option value="other">Other</option>
        </Select>
        <Input label="Date of Birth" type="date" value={dob} onChange={e => setDob(e.target.value)} />
        <Input label="Estimated Age" type="number" value={age} onChange={e => setAge(e.target.value)} placeholder="If DOB unknown" />
        <Input label="Presenting Complaint" value={complaint} onChange={e => setComplaint(e.target.value)} placeholder="Chief complaint" />
      </div>
    </Dialog>
  );
}

/* ── Triage Dialog ───────────────────────────────────────────────── */

function ErTriageDialog({ open, encounterId, scales, onClose, onTriaed, onError }: {
  open: boolean;
  encounterId: string;
  scales: TriageScale[];
  onClose: () => void;
  onTriaed: () => void;
  onError: (msg: string) => void;
}) {
  const [scaleId, setScaleId] = useState('');
  const [busy, setBusy] = useState(false);

  const handleAssign = async () => {
    if (!scaleId) { onError('Select a triage level.'); return; }
    setBusy(true);
    try {
      await erApi.assignTriage(encounterId, { scaleId });
      onTriaed();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Triage failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Assign Triage Level" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void handleAssign()} loading={busy}>Assign</Button>
      </>
    }>
      <Select label="Triage Level" value={scaleId} onChange={e => setScaleId(e.target.value)}>
        <option value="">Select level...</option>
        {scales.map(s => (
          <option key={s.id} value={s.id}>{s.level} — {s.name} ({s.code})</option>
        ))}
      </Select>
    </Dialog>
  );
}

/* ── Event Dialog ────────────────────────────────────────────────── */

function ErEventDialog({ open, encounterId, onClose, onRecorded, onError }: {
  open: boolean;
  encounterId: string;
  onClose: () => void;
  onRecorded: () => void;
  onError: (msg: string) => void;
}) {
  const [eventType, setEventType] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);

  const handleRecord = async () => {
    if (!eventType) { onError('Select an event type.'); return; }
    setBusy(true);
    try {
      await erApi.addEvent(encounterId, { eventType, notes: notes.trim() || undefined });
      onRecorded();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Failed to record event.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Record ER Event" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void handleRecord()} loading={busy}>Record</Button>
      </>
    }>
      <Select label="Event Type" value={eventType} onChange={e => setEventType(e.target.value)}>
        <option value="">Select type...</option>
        {EVENT_TYPES.map(t => (
          <option key={t.value} value={t.value}>{t.label}</option>
        ))}
      </Select>
      <Input label="Notes" value={notes} onChange={e => setNotes(e.target.value)} placeholder="Optional clinical notes" />
    </Dialog>
  );
}

/* ── Disposition Dialog ──────────────────────────────────────────── */

function ErDispositionDialog({ open, encounterId, onClose, onDisposed, onError }: {
  open: boolean;
  encounterId: string;
  onClose: () => void;
  onDisposed: () => void;
  onError: (msg: string) => void;
}) {
  const [disposition, setDisposition] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);

  const handleDispose = async () => {
    if (!disposition) { onError('Select a disposition.'); return; }
    setBusy(true);
    try {
      await erApi.disposition(encounterId, { disposition, notes: notes.trim() || undefined });
      onDisposed();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Disposition failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="ER Disposition" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void handleDispose()} loading={busy}>Confirm Disposition</Button>
      </>
    }>
      <Select label="Disposition" value={disposition} onChange={e => setDisposition(e.target.value)}>
        <option value="">Select disposition...</option>
        {DISPOSITION_OPTIONS.map(d => (
          <option key={d.value} value={d.value}>{d.label}</option>
        ))}
      </Select>
      <Input label="Notes" value={notes} onChange={e => setNotes(e.target.value)} placeholder="Optional disposition notes" />
    </Dialog>
  );
}
