import { useCallback, useMemo, useState } from 'react';
import { icuApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import './icu.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface IcuBed {
  id: string;
  bedCode: string;
  status: string;
  acuitySupported: string;
}

interface IcuAdmissionDetail {
  id: string;
  patientId: string;
  icuBedId?: string;
  source?: string | null;
  acuity: string;
  status: string;
  admittedAt?: string | null;
  nextObservationDueAt: string | null;
  recentScores: Array<{ id: string; total: number; severity: string; computedAt: string }>;
  openAlerts: Array<{ id: string; alertType: string; severity: string; message: string; status: string }>;
}

/* ── Constants ───────────────────────────────────────────────────── */

const OBSERVATION_FIELDS = [
  { key: 'heart_rate', label: 'Heart Rate', unit: 'bpm', placeholder: '72' },
  { key: 'bp_systolic', label: 'BP Systolic', unit: 'mmHg', placeholder: '120' },
  { key: 'bp_diastolic', label: 'BP Diastolic', unit: 'mmHg', placeholder: '80' },
  { key: 'resp_rate', label: 'Resp Rate', unit: '/min', placeholder: '16' },
  { key: 'spo2', label: 'SpO₂', unit: '%', placeholder: '98' },
  { key: 'temperature', label: 'Temperature', unit: '°C', placeholder: '36.5' },
  { key: 'gcs', label: 'GCS', unit: '', placeholder: '15' },
];

const ACUITY_OPTIONS = [
  { value: 'critical', label: 'Critical', color: '#dc2626', bg: '#fee2e2' },
  { value: 'high', label: 'High', color: '#ea580c', bg: '#ffedd5' },
  { value: 'moderate', label: 'Moderate', color: '#f59e0b', bg: '#fef3c7' },
  { value: 'low', label: 'Low', color: '#10b981', bg: '#ecfdf5' },
];

const BED_STATUS_CONFIG: Record<string, { label: string; color: string; bg: string; border: string }> = {
  available: { label: 'Available', color: '#10b981', bg: '#ecfdf5', border: '#d1fae5' },
  occupied: { label: 'Occupied', color: '#ef4444', bg: '#fef2f2', border: '#fecaca' },
  reserved: { label: 'Reserved', color: '#f59e0b', bg: '#fffbeb', border: '#fde68a' },
  out_of_service: { label: 'Out of Service', color: '#6b7280', bg: '#f9fafb', border: '#e5e7eb' },
};

const ALERT_SEVERITY_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  critical: { label: 'CRITICAL', color: '#dc2626', bg: '#fee2e2' },
  warning: { label: 'Warning', color: '#ea580c', bg: '#ffedd5' },
  info: { label: 'Info', color: '#3b82f6', bg: '#eff6ff' },
};

const SCORE_SEVERITY_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  critical: { label: 'Critical', color: '#dc2626', bg: '#fee2e2' },
  severe: { label: 'Severe', color: '#ea580c', bg: '#ffedd5' },
  moderate: { label: 'Moderate', color: '#f59e0b', bg: '#fef3c7' },
  mild: { label: 'Mild', color: '#10b981', bg: '#ecfdf5' },
  none: { label: 'Normal', color: '#6b7280', bg: '#f9fafb' },
};

const NOTE_TYPES = [
  { value: 'progress', label: 'Progress Note' },
  { value: 'nursing', label: 'Nursing Note' },
  { value: 'handover', label: 'Handover Note' },
  { value: 'transfer', label: 'Transfer Note' },
  { value: 'procedure', label: 'Procedure Note' },
  { value: 'discharge', label: 'Discharge Note' },
];

/* ── Main Component ──────────────────────────────────────────────── */

export function IcuPage() {
  const beds = useFetch(() => icuApi.beds(), ['icu-beds']);
  const admissions = useFetch(() => icuApi.admissions(), ['icu-admissions']);
  const [selectedAdmission, setSelectedAdmission] = useState<IcuAdmissionDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'beds' | 'admissions' | 'alerts'>('beds');

  // Dialog states
  const [showAdmitDialog, setShowAdmitDialog] = useState(false);
  const [showObsDialog, setShowObsDialog] = useState(false);
  const [showNotesDialog, setShowNotesDialog] = useState(false);
  const [showTransferDialog, setShowTransferDialog] = useState(false);

  // Admission form
  const [admitPatientId, setAdmitPatientId] = useState('');
  const [admitBedId, setAdmitBedId] = useState('');
  const [admitSource, setAdmitSource] = useState('emergency');
  const [admitAcuity, setAdmitAcuity] = useState('moderate');
  const [admitInterval, setAdmitInterval] = useState('30');
  const [admitNotes, setAdmitNotes] = useState('');
  const [admitting, setAdmitting] = useState(false);

  // Observation form
  const [obsValues, setObsValues] = useState<Record<string, string>>({});
  const [obsNotes, setObsNotes] = useState('');
  const [recording, setRecording] = useState(false);

  // Notes form
  const [noteType, setNoteType] = useState('progress');
  const [noteContent, setNoteContent] = useState('');
  const [savingNote, setSavingNote] = useState(false);

  // Transfer form
  const [transferNotes, setTransferNotes] = useState('');
  const [transferring, setTransferring] = useState(false);

  /* ── Handlers ────────────────────────────────────────────── */

  const refreshAll = useCallback(() => {
    void beds.refresh();
    if (selectedAdmission) {
      void icuApi.show(selectedAdmission.id).then(d => setSelectedAdmission(d));
    }
  }, [beds, selectedAdmission]);

  const handleAdmit = useCallback(async () => {
    if (!admitPatientId || !admitBedId) { setError('Patient and bed are required.'); return; }
    setAdmitting(true); setError(null);
    try {
      await icuApi.admit({
        patientId: admitPatientId,
        icuBedId: admitBedId,
        source: admitSource || undefined,
        acuity: admitAcuity || undefined,
        observationIntervalMinutes: parseInt(admitInterval, 10) || undefined,
        handoverNotes: admitNotes.trim() || undefined,
      });
      setShowAdmitDialog(false);
      setAdmitPatientId(''); setAdmitBedId(''); setAdmitNotes('');
      void beds.refresh();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Admission failed.');
    } finally { setAdmitting(false); }
  }, [admitPatientId, admitBedId, admitSource, admitAcuity, admitInterval, admitNotes, beds]);

  const handleRecordObs = useCallback(async () => {
    if (!selectedAdmission) return;
    const values: Record<string, number> = {};
    for (const f of OBSERVATION_FIELDS) {
      if (obsValues[f.key]) values[f.key] = parseFloat(obsValues[f.key]);
    }
    if (Object.keys(values).length === 0) { setError('Enter at least one observation.'); return; }
    setRecording(true); setError(null);
    try {
      await icuApi.recordObservation(selectedAdmission.id, {
        values,
        notes: obsNotes.trim() || undefined,
      });
      setShowObsDialog(false);
      setObsValues({}); setObsNotes('');
      void icuApi.show(selectedAdmission.id).then(d => setSelectedAdmission(d));
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to record observation.');
    } finally { setRecording(false); }
  }, [selectedAdmission, obsValues, obsNotes]);

  const handleAcknowledgeAlert = useCallback(async (alertId: string) => {
    try {
      await icuApi.acknowledgeAlert(alertId);
      if (selectedAdmission) {
        void icuApi.show(selectedAdmission.id).then(d => setSelectedAdmission(d));
      }
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to acknowledge alert.');
    }
  }, [selectedAdmission]);

  const handleDocumentCare = useCallback(async () => {
    if (!selectedAdmission) return;
    if (!noteContent.trim()) { setError('Note content is required.'); return; }
    setSavingNote(true); setError(null);
    try {
      await icuApi.documentCare(selectedAdmission.id, { noteType, content: noteContent.trim() });
      setShowNotesDialog(false);
      setNoteContent('');
      void icuApi.show(selectedAdmission.id).then(d => setSelectedAdmission(d));
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to save note.');
    } finally { setSavingNote(false); }
  }, [selectedAdmission, noteType, noteContent]);

  const handleTransfer = useCallback(async () => {
    if (!selectedAdmission) return;
    setTransferring(true); setError(null);
    try {
      await icuApi.transferOut(selectedAdmission.id, { handoverNotes: transferNotes.trim() || undefined });
      setShowTransferDialog(false);
      setTransferNotes('');
      setSelectedAdmission(null);
      void beds.refresh();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Transfer failed.');
    } finally { setTransferring(false); }
  }, [selectedAdmission, transferNotes, beds]);

  const handleSelectBed = useCallback(async (bed: IcuBed) => {
    if (bed.status !== 'occupied') {
      // Pre-fill the admit dialog with this bed
      setAdmitBedId(bed.id);
      return;
    }
    const allAds = admissions.data ?? [];
    const match = allAds.find(a => a.icuBedId === bed.id && a.status === 'admitted');
    if (match) {
      try {
        const detail = await icuApi.show(match.id);
        setSelectedAdmission(detail);
        setActiveTab('alerts');
      } catch {
        // If we can't load, just open the admit dialog pre-filled
        setAdmitBedId(bed.id);
      }
    } else {
      setAdmitBedId(bed.id);
    }
  }, [admissions]);

  /* ── Census calculations ────────────────────────────────── */
  const allBeds = beds.data ?? [];
  const census = useMemo(() => {
    const total = allBeds.length;
    const available = allBeds.filter(b => b.status === 'available').length;
    const occupied = allBeds.filter(b => b.status === 'occupied').length;
    const reserved = allBeds.filter(b => b.status === 'reserved').length;
    const outOfService = allBeds.filter(b => b.status === 'out_of_service').length;
    const acuitySupported = allBeds.filter(b => b.acuitySupported === 'true').length;
    const occupancyRate = total > 0 ? Math.round((occupied / total) * 100) : 0;
    return { total, available, occupied, reserved, outOfService, acuitySupported, occupancyRate };
  }, [allBeds]);

  /* ── Alert counts ───────────────────────────────────────── */
  const openAlerts = selectedAdmission?.openAlerts ?? [];
  const criticalAlerts = openAlerts.filter(a => a.severity === 'critical');

  if (beds.loading) return <SkeletonTable rows={6} cols={5} />;

  return (
    <div className="page icu-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">ICU & Critical Care</h1>
          <p className="page__subtitle">Critical care command center — monitoring, alerts, and bed management</p>
        </div>
        <div className="icu-actions">
          <Button variant="primary" onClick={() => { setAdmitPatientId(''); setAdmitBedId(''); setAdmitNotes(''); setShowAdmitDialog(true); }}>
            ICU Admission
          </Button>
          <Button variant="ghost" onClick={() => void refreshAll()}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="icu-census">
        <div className="icu-census-card icu-census-card--total">
          <span className="icu-census-value">{census.total}</span>
          <span className="icu-census-label">Total ICU Beds</span>
        </div>
        <div className="icu-census-card icu-census-card--occupied">
          <span className="icu-census-value">{census.occupied}</span>
          <span className="icu-census-label">Occupied</span>
        </div>
        <div className="icu-census-card icu-census-card--available">
          <span className="icu-census-value">{census.available}</span>
          <span className="icu-census-label">Available</span>
        </div>
        <div className="icu-census-card icu-census-card--reserved">
          <span className="icu-census-value">{census.reserved}</span>
          <span className="icu-census-label">Reserved</span>
        </div>
        <div className="icu-census-card icu-census-card--oos">
          <span className="icu-census-value">{census.outOfService}</span>
          <span className="icu-census-label">Out of Service</span>
        </div>
        <div className="icu-census-card icu-census-card--rate">
          <span className="icu-census-value">{census.occupancyRate}%</span>
          <span className="icu-census-label">Occupancy</span>
          <div className="icu-occupancy-bar">
            <div className="icu-occupancy-fill" style={{ width: `${census.occupancyRate}%` }} />
          </div>
        </div>
      </div>

      {/* ── Tab Navigation ──────────────────────────────── */}
      <div className="icu-tabs">
        <button
          className={`icu-tab ${activeTab === 'beds' ? 'icu-tab--active' : ''}`}
          onClick={() => setActiveTab('beds')}
        >
          Bed Board
        </button>
        <button
          className={`icu-tab ${activeTab === 'admissions' ? 'icu-tab--active' : ''}`}
          onClick={() => setActiveTab('admissions')}
        >
          Admissions
        </button>
        <button
          className={`icu-tab ${activeTab === 'alerts' ? 'icu-tab--active' : ''}`}
          onClick={() => setActiveTab('alerts')}
        >
          Alerts {criticalAlerts.length > 0 && <span className="icu-tab-badge icu-tab-badge--danger">{criticalAlerts.length}</span>}
        </button>
      </div>

      {/* ── Bed Board Tab ──────────────────────────────── */}
      {activeTab === 'beds' && (
        <div className="icu-bed-board">
          {allBeds.length === 0 ? (
            <EmptyState title="No ICU beds configured" body="Add ICU beds to begin critical care admissions." />
          ) : (
            <div className="icu-bed-grid">
              {allBeds.map(bed => {
                const cfg = BED_STATUS_CONFIG[bed.status] ?? BED_STATUS_CONFIG.out_of_service;
                return (
                  <div
                    key={bed.id}
                    className={`icu-bed-cell icu-bed-cell--${bed.status}`}
                    style={{ borderColor: cfg.border }}
                    onClick={() => handleSelectBed(bed)}
                    role="button"
                    tabIndex={0}
                    onKeyDown={(e) => { if (e.key === 'Enter') handleSelectBed(bed); }}
                  >
                    <div className="icu-bed-code">{bed.bedCode}</div>
                    <div className="icu-bed-status" style={{ color: cfg.color }}>{cfg.label}</div>
                    {bed.acuitySupported === 'true' && (
                      <div className="icu-bed-acuity">Acuity</div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* ── Admissions Tab ──────────────────────────────── */}
      {activeTab === 'admissions' && (
        <Card className="icu-section-card">
          <div className="icu-section-header">
            <h3>ICU Admissions</h3>
            <Button variant="ghost" size="sm" onClick={() => void admissions.refresh()}>Refresh</Button>
          </div>
          {(admissions.data ?? []).length === 0 ? (
            <EmptyState title="No ICU admissions" body="Admit a patient to see them here." />
          ) : (
            <div className="icu-admission-list">
              {(admissions.data ?? []).map(ad => {
                const cfg = ACUITY_OPTIONS.find(a => a.value === ad.acuity) ?? ACUITY_OPTIONS[2];
                return (
                  <div
                    key={ad.id}
                    className="icu-admission-row"
                    onClick={async () => { try { const d = await icuApi.show(ad.id); setSelectedAdmission(d); } catch { /* ignore */ } }}
                    role="button"
                    tabIndex={0}
                    onKeyDown={async (e) => { if (e.key === 'Enter') { try { const d = await icuApi.show(ad.id); setSelectedAdmission(d); } catch { /* ignore */ } } }}
                  >
                    <div className="icu-admission-info">
                      <span className="icu-admission-patient">{ad.patientId.slice(0, 12)}...</span>
                      <span className="icu-status-badge" style={{ backgroundColor: cfg.bg, color: cfg.color }}>
                        {ad.acuity}
                      </span>
                    </div>
                    <div className="icu-admission-meta">
                      <span>{ad.source ?? "—"}</span>
                      <span>{ad.admittedAt ? new Date(ad.admittedAt).toLocaleString() : "—"}</span>
                    </div>
                    <span className="icu-admission-status" style={{ color: ad.status === 'admitted' ? '#10b981' : '#6b7280' }}>
                      {ad.status}
                    </span>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* ── Alerts Tab ──────────────────────────────────── */}
      {activeTab === 'alerts' && (
        <Card className="icu-section-card">
          <div className="icu-section-header">
            <h3>ICU Alerts</h3>
          </div>
          {openAlerts.length === 0 ? (
            <EmptyState title="No active alerts" body="All ICU alerts have been acknowledged." />
          ) : (
            <div className="icu-alert-list">
              {openAlerts.map(alert => {
                const cfg = ALERT_SEVERITY_CONFIG[alert.severity] ?? ALERT_SEVERITY_CONFIG.info;
                return (
                  <div key={alert.id} className="icu-alert-item" style={{ borderLeftColor: cfg.color }}>
                    <div className="icu-alert-header">
                      <span className="icu-alert-type">{alert.alertType}</span>
                      <span className="icu-alert-severity" style={{ color: cfg.color, backgroundColor: cfg.bg }}>
                        {cfg.label}
                      </span>
                      <Button variant="ghost" size="sm" onClick={() => void handleAcknowledgeAlert(alert.id)}>
                        Acknowledge
                      </Button>
                    </div>
                    <p className="icu-alert-message">{alert.message}</p>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* ── Patient Detail Panel ────────────────────────── */}
      {selectedAdmission && (
        <Card className="icu-detail-panel">
          <div className="icu-detail-header">
            <div>
              <h3>ICU Patient: {selectedAdmission.patientId.slice(0, 12)}...</h3>
              <div className="icu-detail-meta">
                <span className="icu-status-badge" style={{
                  backgroundColor: ACUITY_OPTIONS.find(a => a.value === selectedAdmission.acuity)?.bg,
                  color: ACUITY_OPTIONS.find(a => a.value === selectedAdmission.acuity)?.color,
                }}>
                  {selectedAdmission.acuity}
                </span>
                <span className="icu-detail-source">Source: {selectedAdmission.source ?? '—'}</span>
                <span className="icu-detail-time">
                  Admitted: {selectedAdmission.admittedAt ? new Date(selectedAdmission.admittedAt).toLocaleString() : '—'}
                </span>
              </div>
            </div>
            <div className="icu-detail-actions">
              <Button variant="ghost" size="sm" onClick={() => { setObsValues({}); setObsNotes(''); setShowObsDialog(true); }}>
                Record Observation
              </Button>
              <Button variant="ghost" size="sm" onClick={() => { setNoteContent(''); setShowNotesDialog(true); }}>
                Document Care
              </Button>
              <Button variant="secondary" size="sm" onClick={() => { setTransferNotes(''); setShowTransferDialog(true); }}>
                Transfer Out
              </Button>
            </div>
          </div>

          {/* Warning Scores */}
          {selectedAdmission.recentScores.length > 0 && (
            <div className="icu-scores-section">
              <h4>Recent Warning Scores</h4>
              <div className="icu-scores-list">
                {selectedAdmission.recentScores.map(score => {
                  const cfg = SCORE_SEVERITY_CONFIG[score.severity] ?? SCORE_SEVERITY_CONFIG.none;
                  return (
                    <div key={score.id} className="icu-score-item" style={{ backgroundColor: cfg.bg }}>
                      <span className="icu-score-value" style={{ color: cfg.color }}>{score.total}</span>
                      <span className="icu-score-severity" style={{ color: cfg.color }}>{cfg.label}</span>
                      <span className="icu-score-time">{new Date(score.computedAt).toLocaleTimeString()}</span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Next Observation Due */}
          {selectedAdmission.nextObservationDueAt && (
            <div className="icu-next-obs">
              <span className="icu-next-obs-label">Next observation due:</span>
              <span className="icu-next-obs-time">
                {new Date(selectedAdmission.nextObservationDueAt).toLocaleString()}
              </span>
            </div>
          )}
        </Card>
      )}

      {/* ── Admission Dialog ────────────────────────────── */}
      {showAdmitDialog && (
        <Dialog open onClose={() => setShowAdmitDialog(false)} title="ICU Admission" footer={
          <>
            <Button variant="ghost" onClick={() => setShowAdmitDialog(false)}>Cancel</Button>
            <Button onClick={() => void handleAdmit()} loading={admitting}>Admit to ICU</Button>
          </>
        }>
          <div className="form-grid">
            <Input label="Patient ID" value={admitPatientId} onChange={e => setAdmitPatientId(e.target.value)} placeholder="Patient identifier" />
            <Select label="ICU Bed" value={admitBedId} onChange={e => setAdmitBedId(e.target.value)}>
              <option value="">Select bed ({allBeds.filter(b => b.status === 'available').length} available)...</option>
              {allBeds.filter(b => b.status === 'available').map(b => (
                <option key={b.id} value={b.id}>{b.bedCode}{b.acuitySupported === 'true' ? ' (Acuity)' : ''}</option>
              ))}
            </Select>
            <Select label="Source" value={admitSource} onChange={e => setAdmitSource(e.target.value)}>
              <option value="emergency">Emergency</option>
              <option value="ward">Ward Transfer</option>
              <option value="direct">Direct Admission</option>
              <option value="transfer_in">Transfer from Facility</option>
            </Select>
            <Select label="Acuity Level" value={admitAcuity} onChange={e => setAdmitAcuity(e.target.value)}>
              {ACUITY_OPTIONS.map(a => <option key={a.value} value={a.value}>{a.label}</option>)}
            </Select>
            <Select label="Observation Interval (min)" value={admitInterval} onChange={e => setAdmitInterval(e.target.value)}>
              <option value="15">Every 15 minutes</option>
              <option value="30">Every 30 minutes</option>
              <option value="60">Every hour</option>
              <option value="120">Every 2 hours</option>
            </Select>
            <Input label="Handover Notes" value={admitNotes} onChange={e => setAdmitNotes(e.target.value)} placeholder="Clinical handover notes" />
          </div>
          {allBeds.filter(b => b.status === 'available').length === 0 && (
            <Alert tone="warning">No ICU beds available. Free a bed or add capacity.</Alert>
          )}
        </Dialog>
      )}

      {/* ── Observation Dialog ──────────────────────────── */}
      {showObsDialog && (
        <Dialog open onClose={() => setShowObsDialog(false)} title="Record ICU Observation" footer={
          <>
            <Button variant="ghost" onClick={() => setShowObsDialog(false)}>Cancel</Button>
            <Button onClick={() => void handleRecordObs()} loading={recording}>Record & Score</Button>
          </>
        }>
          <div className="icu-obs-grid">
            {OBSERVATION_FIELDS.map(f => (
              <Input
                key={f.key}
                label={`${f.label} (${f.unit})`}
                type="number"
                step="0.1"
                value={obsValues[f.key] ?? ''}
                onChange={e => setObsValues(prev => ({ ...prev, [f.key]: e.target.value }))}
                placeholder={f.placeholder}
              />
            ))}
          </div>
          <Input label="Notes" value={obsNotes} onChange={e => setObsNotes(e.target.value)} placeholder="Optional clinical notes" />
        </Dialog>
      )}

      {/* ── Notes Dialog ─────────────────────────────────── */}
      {showNotesDialog && (
        <Dialog open onClose={() => setShowNotesDialog(false)} title="ICU Clinical Note" footer={
          <>
            <Button variant="ghost" onClick={() => setShowNotesDialog(false)}>Cancel</Button>
            <Button onClick={() => void handleDocumentCare()} loading={savingNote}>Save Note</Button>
          </>
        }>
          <Select label="Note Type" value={noteType} onChange={e => setNoteType(e.target.value)}>
            {NOTE_TYPES.map(n => <option key={n.value} value={n.value}>{n.label}</option>)}
          </Select>
          <Input label="Clinical Content" value={noteContent} onChange={e => setNoteContent(e.target.value)} placeholder="Clinical documentation" />
        </Dialog>
      )}

      {/* ── Transfer Dialog ──────────────────────────────── */}
      {showTransferDialog && (
        <Dialog open onClose={() => setShowTransferDialog(false)} title="Transfer Out of ICU" footer={
          <>
            <Button variant="ghost" onClick={() => setShowTransferDialog(false)}>Cancel</Button>
            <Button onClick={() => void handleTransfer()} loading={transferring}>Confirm Transfer</Button>
          </>
        }>
          <Input label="Handover Notes" value={transferNotes} onChange={e => setTransferNotes(e.target.value)} placeholder="Transfer handover notes" />
          <p className="icu-transfer-info">
            This will release the ICU bed and transfer the patient to the appropriate ward or unit.
            The bed will enter cleaning status before becoming available.
          </p>
        </Dialog>
      )}
    </div>
  );
}
