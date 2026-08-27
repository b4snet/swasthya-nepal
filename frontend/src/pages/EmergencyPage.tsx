import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { erApi } from '../api/endpoints';
import { EmergencyCommandSurface } from '../components/EmergencyCommandSurface';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import { formatDateTime } from '../components/ui';
import './emergency.css';

/* ── Types ───────────────────────────────────────────────────────── */

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

/* ── Constants ───────────────────────────────────────────────────── */

const DISPOSITION_OPTIONS = [
  { value: 'admit', label: 'Admit to IPD', icon: 'bed' },
  { value: 'transfer', label: 'Transfer to ICU/OT', icon: 'arrowRightLeft' },
  { value: 'home', label: 'Discharge Home', icon: 'home' },
  { value: 'referred', label: 'Refer Out', icon: 'forward' },
  { value: 'ama', label: 'Left Against Medical Advice', icon: 'alertTriangle' },
];

const EVENT_TYPES = [
  { value: 'assessment', label: 'Initial Assessment' },
  { value: 'vitals', label: 'Vitals Recorded' },
  { value: 'medication', label: 'Medication Given' },
  { value: 'order', label: 'Order Placed' },
  { value: 'result', label: 'Result Received' },
  { value: 'consult', label: 'Consultation Requested' },
  { value: 'procedure', label: 'Procedure Performed' },
  { value: 'note', label: 'Clinical Note' },
];

const CONSULT_SPECIALTIES = [
  'Cardiology', 'Neurology', 'Orthopedics', 'General Surgery',
  'Pediatrics', 'Obstetrics & Gynecology', 'Pulmonology',
  'Nephrology', 'Gastroenterology', 'Oncology', 'Psychiatry',
  'ENT', 'Ophthalmology', 'Dermatology', 'Urology',
];

const ACUITY_LABELS: Record<number, { label: string; cssClass: string }> = {
  1: { label: 'Resuscitation', cssClass: 'er-acuity--resus' },
  2: { label: 'Emergency', cssClass: 'er-acuity--emerg' },
  3: { label: 'Urgent', cssClass: 'er-acuity--urgent' },
  4: { label: 'Semi-Urgent', cssClass: 'er-acuity--semi' },
  5: { label: 'Non-Urgent', cssClass: 'er-acuity--non' },
};

/* ── Helpers ─────────────────────────────────────────────────────── */

function computeWaitMinutes(registeredAt: string | null): number {
  if (!registeredAt) return 0;
  return Math.max(0, Math.floor((Date.now() - new Date(registeredAt).getTime()) / 60000));
}

function formatWait(minutes: number): string {
  if (minutes < 60) return `${minutes}m`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function waitUrgency(minutes: number): string {
  if (minutes >= 120) return 'er-wait--critical';
  if (minutes >= 60) return 'er-wait--warning';
  if (minutes >= 30) return 'er-wait--caution';
  return '';
}

/* ── Main Component ──────────────────────────────────────────────── */

export function EmergencyPage() {
  const { organizationId, selectedFacilityId: facilityId } = useTenant();
  const [showRegDialog, setShowRegDialog] = useState(false);
  const [showTriageDialog, setTriageDialog] = useState<ErQueueEntry | null>(null);
  const [showEventDialog, setEventDialog] = useState<ErQueueEntry | null>(null);
  const [showDispDialog, setDispDialog] = useState<ErQueueEntry | null>(null);
  const [showConsultDialog, setConsultDialog] = useState<ErQueueEntry | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [activeZone, setActiveZone] = useState<'all' | 'untriaged' | 'waiting' | 'care' | 'dispo'>('all');
  const [selectedEntry, setSelectedEntry] = useState<ErQueueEntry | null>(null);

  const queue = useFetch(() => erApi.queue(), ['er-queue']);
  const scales = useFetch(() => organizationId ? erApi.triageScales(organizationId) : Promise.resolve([]), ['er-scales', organizationId]);

  const refreshQueue = useCallback(() => { void queue.refresh(); }, [queue]);

  // Auto-refresh every 15 seconds for emergency
  useEffect(() => {
    const id = setInterval(refreshQueue, 15000);
    return () => clearInterval(id);
  }, [refreshQueue]);

  const entries = useMemo(() => (queue.data ?? []) as ErQueueEntry[], [queue.data]);

  /* ── Census calculations ──────────────────────────────────────── */
  const census = useMemo(() => {
    const total = entries.length;
    const untriaged = entries.filter(e => e.triageLevel === null).length;
    const triaged = entries.filter(e => e.triageLevel !== null).length;
    const highAcuity = entries.filter(e => e.triageLevel !== null && e.triageLevel <= 2).length;
    const avgWait = total > 0
      ? Math.round(entries.reduce((sum, e) => sum + computeWaitMinutes(e.registeredAt), 0) / total)
      : 0;
    const longestWait = total > 0
      ? Math.max(...entries.map(e => computeWaitMinutes(e.registeredAt)))
      : 0;
    return { total, untriaged, triaged, highAcuity, avgWait, longestWait };
  }, [entries]);

  /* ── Zone filtering ───────────────────────────────────────────── */
  const filteredEntries = useMemo(() => {
    switch (activeZone) {
      case 'untriaged': return entries.filter(e => e.triageLevel === null);
      case 'waiting': return entries.filter(e => e.triageLevel !== null && e.triageLevel >= 3);
      case 'care': return entries.filter(e => e.triageLevel !== null && e.triageLevel <= 2);
      case 'dispo': return entries.filter(e => e.triageLevel !== null);
      default: return entries;
    }
  }, [entries, activeZone]);

  if (queue.loading) return <SkeletonTable rows={6} cols={5} />;



  return (
    <div className="page er-page">
      {/* ── Command Center Header ──────────────────────────────── */}
      <header className="page__head">
        <div>
          <h1 className="page__title">Emergency Department</h1>
          <p className="page__subtitle">Real-time operational overview</p>
        </div>
        <div className="er-actions">
          <Button variant="primary" onClick={() => setShowRegDialog(true)}>
            New Registration
          </Button>
          <Button variant="ghost" onClick={() => void refreshQueue()}>
            Refresh
          </Button>
        </div>
      </header>

      {/* ── Emergency Command Surface (Phase 123) ── */}
      <EmergencyCommandSurface />

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ───────────────────────────────────── */}
      <div className="er-census">
        <div className="er-census-card er-census-card--total">
          <span className="er-census-value">{census.total}</span>
          <span className="er-census-label">Total in ED</span>
        </div>
        <div className="er-census-card er-census-card--untriaged">
          <span className="er-census-value">{census.untriaged}</span>
          <span className="er-census-label">Awaiting Triage</span>
        </div>
        <div className="er-census-card er-census-card--high">
          <span className="er-census-value">{census.highAcuity}</span>
          <span className="er-census-label">High Acuity (1-2)</span>
        </div>
        <div className="er-census-card er-census-card--wait">
          <span className="er-census-value">{formatWait(census.avgWait)}</span>
          <span className="er-census-label">Avg Wait</span>
        </div>
        <div className="er-census-card er-census-card--longest">
          <span className="er-census-value">{formatWait(census.longestWait)}</span>
          <span className="er-census-label">Longest Wait</span>
        </div>
      </div>

      {/* ── Zone Tabs ──────────────────────────────────────────── */}
      <div className="er-zone-tabs">
        <button
          className={`er-zone-tab ${activeZone === 'all' ? 'er-zone-tab--active' : ''}`}
          onClick={() => setActiveZone('all')}
        >
          All <span className="er-zone-count">{census.total}</span>
        </button>
        <button
          className={`er-zone-tab ${activeZone === 'untriaged' ? 'er-zone-tab--active' : ''}`}
          onClick={() => setActiveZone('untriaged')}
        >
          Untriaged <span className="er-zone-count er-zone-count--warn">{census.untriaged}</span>
        </button>
        <button
          className={`er-zone-tab ${activeZone === 'care' ? 'er-zone-tab--active' : ''}`}
          onClick={() => setActiveZone('care')}
        >
          Acute Care <span className="er-zone-count er-zone-count--danger">{census.highAcuity}</span>
        </button>
        <button
          className={`er-zone-tab ${activeZone === 'waiting' ? 'er-zone-tab--active' : ''}`}
          onClick={() => setActiveZone('waiting')}
        >
          Waiting
        </button>
        <button
          className={`er-zone-tab ${activeZone === 'dispo' ? 'er-zone-tab--active' : ''}`}
          onClick={() => setActiveZone('dispo')}
        >
          Disposition
        </button>
      </div>

      {/* ── Queue List ─────────────────────────────────────────── */}
      <Card className="er-queue-card">
        {filteredEntries.length === 0 ? (
          <EmptyState
            title={activeZone === 'all' ? 'Emergency queue is clear' : `No patients in ${activeZone}`}
            body="No patients currently match this filter."
          />
        ) : (
          <div className="er-queue-list">
            {filteredEntries.map((entry) => {
              const waitMin = computeWaitMinutes(entry.registeredAt);
              const acuity = entry.triageLevel !== null ? ACUITY_LABELS[entry.triageLevel] : null;
              const isSelected = selectedEntry?.encounterId === entry.encounterId;

              return (
                <div
                  key={entry.encounterId}
                  className={`er-queue-item ${isSelected ? 'er-queue-item--selected' : ''} ${entry.triageLevel !== null && entry.triageLevel <= 2 ? 'er-queue-item--acute' : ''}`}
                  onClick={() => setSelectedEntry(isSelected ? null : entry)}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => { if (e.key === 'Enter') setSelectedEntry(isSelected ? null : entry); }}
                >
                  {/* Acuity Badge */}
                  <div className="er-queue-triage">
                    {entry.triageLevel !== null ? (
                      <span
                        className={`er-acuity-badge ${acuity?.cssClass ?? ''}`}
                        title={acuity?.label}
                      >
                        {entry.triageLevel}
                      </span>
                    ) : (
                      <span className="er-acuity-badge er-acuity-badge--untriaged" title="Untriaged">?</span>
                    )}
                  </div>

                  {/* Patient Info */}
                  <div className="er-queue-info">
                    <Link
                      to={`/patients/${entry.patientId}`}
                      className="er-queue-patient"
                      onClick={(e) => e.stopPropagation()}
                    >
                      {entry.patientId.slice(0, 8)}...
                    </Link>
                    {entry.presentingComplaint && (
                      <span className="er-queue-complaint">{entry.presentingComplaint}</span>
                    )}
                    <div className="er-queue-meta">
                      {acuity && (
                        <span className={`er-acuity-label ${acuity.cssClass}`}>{acuity.label}</span>
                      )}
                      <span className="er-queue-time">
                        {entry.registeredAt ? formatDateTime(entry.registeredAt) : '—'}
                      </span>
                    </div>
                  </div>

                  {/* Wait Time */}
                  <div className="er-queue-wait">
                    <span className={`er-wait-time ${waitUrgency(waitMin)}`}>
                      {formatWait(waitMin)}
                    </span>
                  </div>

                  {/* Actions */}
                  <div className="er-queue-actions" onClick={(e) => e.stopPropagation()}>
                    {entry.triageLevel === null && (
                      <Button variant="primary" size="sm" onClick={() => setTriageDialog(entry)}>
                        Triage
                      </Button>
                    )}
                    <Button variant="ghost" size="sm" onClick={() => setEventDialog(entry)}>
                      Event
                    </Button>
                    {entry.triageLevel !== null && (
                      <>
                        <Button variant="ghost" size="sm" onClick={() => setConsultDialog(entry)}>
                          Consult
                        </Button>
                        <Button variant="secondary" size="sm" onClick={() => setDispDialog(entry)}>
                          Disposition
                        </Button>
                      </>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      {/* ── Selected Patient Detail Panel ──────────────────────── */}
      {selectedEntry && (
        <Card className="er-detail-panel">
          <div className="er-detail-header">
            <div>
              <h3>Patient: {selectedEntry.patientId.slice(0, 12)}...</h3>
              {selectedEntry.presentingComplaint && (
                <p className="er-detail-complaint">{selectedEntry.presentingComplaint}</p>
              )}
            </div>
            <div className="er-detail-actions">
              <Link to={`/encounters/${selectedEntry.encounterId}`}>
                <Button variant="ghost" size="sm">Open Encounter</Button>
              </Link>
              <Link to={`/patients/${selectedEntry.patientId}`}>
                <Button variant="ghost" size="sm">Patient Record</Button>
              </Link>
            </div>
          </div>
          <div className="er-detail-grid">
            <div className="er-detail-item">
              <span className="er-detail-label">Status</span>
              <span className="er-detail-value">
                {selectedEntry.triageLevel !== null ? 'Triaged' : 'Awaiting Triage'}
              </span>
            </div>
            <div className="er-detail-item">
              <span className="er-detail-label">Wait</span>
              <span className="er-detail-value">
                {formatWait(computeWaitMinutes(selectedEntry.registeredAt))}
              </span>
            </div>
            <div className="er-detail-item">
              <span className="er-detail-label">Facility</span>
              <span className="er-detail-value">{selectedEntry.facilityId.slice(0, 8)}...</span>
            </div>
            <div className="er-detail-item">
              <span className="er-detail-label">Registered</span>
              <span className="er-detail-value">
                {selectedEntry.registeredAt ? formatDateTime(selectedEntry.registeredAt) : '—'}
              </span>
            </div>
          </div>
          <div className="er-detail-quick-actions">
            {selectedEntry.triageLevel === null && (
              <Button variant="primary" size="sm" onClick={() => setTriageDialog(selectedEntry)}>
                Assign Triage
              </Button>
            )}
            <Button variant="ghost" size="sm" onClick={() => setEventDialog(selectedEntry)}>
              Record Event
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setConsultDialog(selectedEntry)}>
              Request Consult
            </Button>
            {selectedEntry.triageLevel !== null && (
              <Button variant="secondary" size="sm" onClick={() => setDispDialog(selectedEntry)}>
                Set Disposition
              </Button>
            )}
          </div>
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────────── */}
      <ErRegistrationDialog
        open={showRegDialog}
        facilityId={facilityId}
        onClose={() => { setShowRegDialog(false); setError(null); }}
        onRegistered={() => { setShowRegDialog(false); void refreshQueue(); }}
        onError={setError}
      />

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

      {showEventDialog && (
        <ErEventDialog
          open
          encounterId={showEventDialog.encounterId}
          onClose={() => { setEventDialog(null); setError(null); }}
          onRecorded={() => { setEventDialog(null); }}
          onError={setError}
        />
      )}

      {showDispDialog && (
        <ErDispositionDialog
          open
          encounterId={showDispDialog.encounterId}
          onClose={() => { setDispDialog(null); setError(null); }}
          onDisposed={() => { setDispDialog(null); void refreshQueue(); }}
          onError={setError}
        />
      )}

      {showConsultDialog && (
        <ErConsultDialog
          open
          encounterId={showConsultDialog.encounterId}
          onClose={() => { setConsultDialog(null); setError(null); }}
          onRequested={() => { setConsultDialog(null); }}
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
  const [arrivalMode, setArrivalMode] = useState('walk_in');
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
        <Button onClick={() => void handleSubmit()} loading={busy}>Register Patient</Button>
      </>
    }>
      <div className="form-grid">
        <Select label="Arrival Mode" value={arrivalMode} onChange={e => setArrivalMode(e.target.value)}>
          <option value="walk_in">Walk-in</option>
          <option value="ambulance">Ambulance</option>
          <option value="referral">Referral</option>
          <option value="transfer">Transfer</option>
        </Select>
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
  const [overrideReason, setOverrideReason] = useState('');
  const [busy, setBusy] = useState(false);

  const selectedScale = scales.find(s => s.id === scaleId);

  const handleAssign = async () => {
    if (!scaleId) { onError('Select a triage level.'); return; }
    setBusy(true);
    try {
      await erApi.assignTriage(encounterId, {
        scaleId,
        overrideReason: overrideReason.trim() || undefined,
      });
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
        <Button onClick={() => void handleAssign()} loading={busy}>Assign Triage</Button>
      </>
    }>
      <div className="er-triage-preview">
        {scales.map(s => {
          const acuity = ACUITY_LABELS[s.level];
          return (
            <button
              key={s.id}
              className={`er-triage-option ${scaleId === s.id ? 'er-triage-option--selected' : ''} ${acuity?.cssClass ?? ''}`}
              onClick={() => setScaleId(s.id)}
              type="button"
            >
              <span className="er-triage-option-level">{s.level}</span>
              <span className="er-triage-option-name">{s.name}</span>
              <span className="er-triage-option-code">{s.code}</span>
            </button>
          );
        })}
      </div>
      {selectedScale && (
        <p className="er-triage-reassessment">
          Reassessment interval: {selectedScale.reassessmentMinutes} minutes
        </p>
      )}
      <Input
        label="Override Reason (optional)"
        value={overrideReason}
        onChange={e => setOverrideReason(e.target.value)}
        placeholder="If triage level differs from assessment"
      />
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
    <Dialog open={open} onClose={onClose} title="Record Emergency Event" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void handleRecord()} loading={busy}>Record Event</Button>
      </>
    }>
      <Select label="Event Type" value={eventType} onChange={e => setEventType(e.target.value)}>
        <option value="">Select type...</option>
        {EVENT_TYPES.map(t => (
          <option key={t.value} value={t.value}>{t.label}</option>
        ))}
      </Select>
      <Input label="Clinical Notes" value={notes} onChange={e => setNotes(e.target.value)} placeholder="Optional clinical notes" />
    </Dialog>
  );
}

/* ── Consultation Dialog ─────────────────────────────────────────── */

function ErConsultDialog({ open, encounterId, onClose, onRequested, onError }: {
  open: boolean;
  encounterId: string;
  onClose: () => void;
  onRequested: () => void;
  onError: (msg: string) => void;
}) {
  const [specialty, setSpecialty] = useState('');
  const [urgency, setUrgency] = useState('urgent');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const handleRequest = async () => {
    if (!specialty) { onError('Select a specialty.'); return; }
    setBusy(true);
    try {
      await erApi.addEvent(encounterId, {
        eventType: 'consult',
        notes: `[${urgency.toUpperCase()}] Consult to ${specialty}: ${reason.trim()}`,
      });
      onRequested();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Consult request failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Request Consultation" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void handleRequest()} loading={busy}>Request Consult</Button>
      </>
    }>
      <Select label="Specialty" value={specialty} onChange={e => setSpecialty(e.target.value)}>
        <option value="">Select specialty...</option>
        {CONSULT_SPECIALTIES.map(s => (
          <option key={s} value={s}>{s}</option>
        ))}
      </Select>
      <Select label="Urgency" value={urgency} onChange={e => setUrgency(e.target.value)}>
        <option value="stat">STAT (Immediate)</option>
        <option value="urgent">Urgent</option>
        <option value="routine">Routine</option>
      </Select>
      <Input label="Reason for Consultation" value={reason} onChange={e => setReason(e.target.value)} placeholder="Clinical reason" />
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
  const [diagnosis, setDiagnosis] = useState('');
  const [busy, setBusy] = useState(false);

  const handleDispose = async () => {
    if (!disposition) { onError('Select a disposition.'); return; }
    setBusy(true);
    try {
      await erApi.disposition(encounterId, {
        disposition,
        notes: notes.trim() || undefined,
        admittingDiagnosis: diagnosis.trim() || undefined,
      });
      onDisposed();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Disposition failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Emergency Disposition" footer={
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
      {(disposition === 'admit' || disposition === 'transfer') && (
        <Input label="Admitting Diagnosis" value={diagnosis} onChange={e => setDiagnosis(e.target.value)} placeholder="Primary diagnosis for admission" />
      )}
      <Input label="Disposition Notes" value={notes} onChange={e => setNotes(e.target.value)} placeholder="Optional notes" />
    </Dialog>
  );
}
