import { useCallback, useState } from 'react';
import { icuApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import './pages.css';

const OBSERVATION_FIELDS = [
  { key: 'heart_rate', label: 'Heart Rate', unit: 'bpm' },
  { key: 'bp_systolic', label: 'BP Systolic', unit: 'mmHg' },
  { key: 'bp_diastolic', label: 'BP Diastolic', unit: 'mmHg' },
  { key: 'resp_rate', label: 'Resp Rate', unit: '/min' },
  { key: 'spo2', label: 'SpO₂', unit: '%' },
  { key: 'temperature', label: 'Temperature', unit: '°C' },
  { key: 'gcs', label: 'GCS', unit: '' },
];

const ACUITY_OPTIONS = [
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'moderate', label: 'Moderate' },
  { value: 'low', label: 'Low' },
];

export function IcuPage() {
  const beds = useFetch(() => icuApi.beds(), ['icu-beds']);

  const [showBedDialog, setShowBedDialog] = useState(false);
  const [showAdmitDialog, setShowAdmitDialog] = useState(false);
  const [showObsDialog, setShowObsDialog] = useState<string | null>(null);
  const [showNotesDialog, setShowNotesDialog] = useState<string | null>(null);
  const [showTransferDialog, setShowTransferDialog] = useState<string | null>(null);
  const [showDetailDialog, setShowDetailDialog] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Create bed
  const [bedCode, setBedCode] = useState('');
  const [acuitySupported, setAcuitySupported] = useState('');
  const [creatingBed, setCreatingBed] = useState(false);

  const handleCreateBed = useCallback(async () => {
    if (!bedCode.trim()) return;
    setCreatingBed(true);
    setError(null);
    try {
      await icuApi.createBed({ bedCode: bedCode.trim(), acuitySupported: acuitySupported || undefined });
      setShowBedDialog(false);
      setBedCode('');
      beds.refresh();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to create bed');
    } finally {
      setCreatingBed(false);
    }
  }, [bedCode, acuitySupported, beds]);

  // Admit
  const [admitPatientId, setAdmitPatientId] = useState('');
  const [admitBedId, setAdmitBedId] = useState('');
  const [admitSource, setAdmitSource] = useState('');
  const [admitAcuity, setAdmitAcuity] = useState('');
  const [admitInterval, setAdmitInterval] = useState('30');
  const [admitNotes, setAdmitNotes] = useState('');
  const [admitting, setAdmitting] = useState(false);

  const handleAdmit = useCallback(async () => {
    if (!admitPatientId.trim() || !admitBedId) return;
    setAdmitting(true);
    setError(null);
    try {
      await icuApi.admit({
        patientId: admitPatientId.trim(),
        icuBedId: admitBedId,
        source: admitSource || undefined,
        acuity: admitAcuity || undefined,
        observationIntervalMinutes: admitInterval ? parseInt(admitInterval, 10) : undefined,
        handoverNotes: admitNotes || undefined,
      });
      setShowAdmitDialog(false);
      setAdmitPatientId('');
      setAdmitBedId('');
      beds.refresh();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to admit patient');
    } finally {
      setAdmitting(false);
    }
  }, [admitPatientId, admitBedId, admitSource, admitAcuity, admitInterval, admitNotes, beds]);

  // Observation
  const [obsValues, setObsValues] = useState<Record<string, string>>({});
  const [obsNotes, setObsNotes] = useState('');
  const [recording, setRecording] = useState(false);

  const handleRecordObs = useCallback(async () => {
    if (!showObsDialog) return;
    setRecording(true);
    setError(null);
    try {
      const numericValues: Record<string, number> = {};
      for (const [k, v] of Object.entries(obsValues)) {
        if (v.trim()) numericValues[k] = parseFloat(v);
      }
      await icuApi.recordObservation(showObsDialog, {
        values: numericValues,
        notes: obsNotes || undefined,
      });
      setShowObsDialog(null);
      setObsValues({});
      setObsNotes('');
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to record observations');
    } finally {
      setRecording(false);
    }
  }, [showObsDialog, obsValues, obsNotes]);

  // Notes
  const [noteType, setNoteType] = useState('progress');
  const [noteContent, setNoteContent] = useState('');
  const [savingNote, setSavingNote] = useState(false);

  const handleSaveNote = useCallback(async () => {
    if (!showNotesDialog || !noteContent.trim()) return;
    setSavingNote(true);
    setError(null);
    try {
      await icuApi.documentCare(showNotesDialog, { noteType, content: noteContent.trim() });
      setShowNotesDialog(null);
      setNoteContent('');
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to save note');
    } finally {
      setSavingNote(false);
    }
  }, [showNotesDialog, noteType, noteContent]);

  // Transfer
  const [transferNotes, setTransferNotes] = useState('');
  const [transferring, setTransferring] = useState(false);

  const handleTransfer = useCallback(async () => {
    if (!showTransferDialog) return;
    setTransferring(true);
    setError(null);
    try {
      await icuApi.transferOut(showTransferDialog, {
        handoverNotes: transferNotes || undefined,
      });
      setShowTransferDialog(null);
      setTransferNotes('');
      beds.refresh();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to transfer');
    } finally {
      setTransferring(false);
    }
  }, [showTransferDialog, transferNotes, beds]);

  return (
    <div className="page-container">
      <div className="page-header">
        <div>
          <h1 className="page-title">ICU & Critical Care</h1>
          <p className="page-subtitle">Bed management, admissions, and monitoring</p>
        </div>
        <div className="page-actions">
          <Button variant="secondary" onClick={() => setShowBedDialog(true)}>Add Bed</Button>
          <Button onClick={() => setShowAdmitDialog(true)}>Admit Patient</Button>
        </div>
      </div>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* Stats */}
      <div className="stats-grid">
        <Card className="stat-card">
          <div className="stat-label">Total Beds</div>
          <div className="stat-value">{beds.loading ? '—' : (beds.data ?? []).length}</div>
        </Card>
        <Card className="stat-card">
          <div className="stat-label">Occupied</div>
          <div className="stat-value" style={{ color: 'var(--color-danger, #ef4444)' }}>
            {beds.loading ? '—' : (beds.data ?? []).filter((b) => b.status === 'occupied').length}
          </div>
        </Card>
        <Card className="stat-card">
          <div className="stat-label">Available</div>
          <div className="stat-value" style={{ color: 'var(--color-success, #10b981)' }}>
            {beds.loading ? '—' : (beds.data ?? []).filter((b) => b.status === 'available').length}
          </div>
        </Card>
        <Card className="stat-card">
          <div className="stat-label">Acuity-Supported</div>
          <div className="stat-value">
            {beds.loading ? '—' : (beds.data ?? []).filter((b) => b.acuitySupported === 'true').length}
          </div>
        </Card>
      </div>

      {/* Beds Table */}
      {beds.loading ? (
        <SkeletonTable rows={4} cols={4} />
      ) : (beds.data ?? []).length === 0 ? (
        <EmptyState title="No ICU beds" body="Add ICU beds to begin admissions." />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>Bed Code</th>
                <th>Status</th>
                <th>Acuity</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {(beds.data ?? []).map((bed) => (
                <tr key={bed.id}>
                  <td className="font-medium">{bed.bedCode}</td>
                  <td>
                    <span className={`badge badge--${bed.status === 'occupied' ? 'danger' : bed.status === 'available' ? 'success' : 'neutral'}`}>
                      {bed.status}
                    </span>
                  </td>
                  <td>{bed.acuitySupported === 'true' ? 'Yes' : 'No'}</td>
                  <td>
                    <Button size="sm" variant="secondary" onClick={() => setShowDetailDialog(bed.id)}>
                      View
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {/* Create Bed Dialog */}
      <Dialog open={showBedDialog} onClose={() => setShowBedDialog(false)} title="Add ICU Bed">
        <div className="dialog-form">
          <Input label="Bed Code" value={bedCode} onChange={(e) => setBedCode(e.target.value)} placeholder="e.g. ICU-01" />
          <div className="form-field">
            <label className="form-label">Acuity Scoring</label>
            <select className="input" value={acuitySupported} onChange={(e) => setAcuitySupported(e.target.value)}>
              <option value="">Not supported</option>
              <option value="true">Supported</option>
            </select>
          </div>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={() => setShowBedDialog(false)}>Cancel</Button>
            <Button onClick={handleCreateBed} disabled={creatingBed || !bedCode.trim()}>
              {creatingBed ? 'Creating…' : 'Create Bed'}
            </Button>
          </div>
        </div>
      </Dialog>

      {/* Admit Dialog */}
      <Dialog open={showAdmitDialog} onClose={() => setShowAdmitDialog(false)} title="Admit Patient to ICU">
        <div className="dialog-form">
          <Input label="Patient ID" value={admitPatientId} onChange={(e) => setAdmitPatientId(e.target.value)} placeholder="Patient ID or MRN" />
          <Select label="Bed" value={admitBedId} onChange={(e) => setAdmitBedId(e.target.value)}>
            <option value="">Select bed…</option>
            {(beds.data ?? []).filter((b) => b.status === 'available').map((b) => (
              <option key={b.id} value={b.id}>{b.bedCode}</option>
            ))}
          </Select>
          <Select label="Source" value={admitSource} onChange={(e) => setAdmitSource(e.target.value)}>
            <option value="">Select source…</option>
            <option value="er">Emergency</option>
            <option value="ward">Ward</option>
            <option value="ot">OT</option>
            <option value="transfer">Transfer</option>
          </Select>
          <Select label="Acuity" value={admitAcuity} onChange={(e) => setAdmitAcuity(e.target.value)}>
            <option value="">Select acuity…</option>
            {ACUITY_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
          <Input label="Observation Interval (min)" type="number" value={admitInterval} onChange={(e) => setAdmitInterval(e.target.value)} />
          <Input label="Handover Notes" value={admitNotes} onChange={(e) => setAdmitNotes(e.target.value)} placeholder="Optional notes" />
          <div className="dialog-actions">
            <Button variant="secondary" onClick={() => setShowAdmitDialog(false)}>Cancel</Button>
            <Button onClick={handleAdmit} disabled={admitting || !admitPatientId.trim() || !admitBedId}>
              {admitting ? 'Admitting…' : 'Admit'}
            </Button>
          </div>
        </div>
      </Dialog>

      {/* Record Observation Dialog */}
      <Dialog open={!!showObsDialog} onClose={() => setShowObsDialog(null)} title="Record ICU Observations">
        <div className="dialog-form">
          {OBSERVATION_FIELDS.map((f) => (
            <Input
              key={f.key}
              label={`${f.label} (${f.unit})`}
              type="number"
              value={obsValues[f.key] ?? ''}
              onChange={(e) => setObsValues((prev) => ({ ...prev, [f.key]: e.target.value }))}
              placeholder={f.label}
            />
          ))}
          <Input label="Notes" value={obsNotes} onChange={(e) => setObsNotes(e.target.value)} placeholder="Optional clinical notes" />
          <div className="dialog-actions">
            <Button variant="secondary" onClick={() => setShowObsDialog(null)}>Cancel</Button>
            <Button onClick={handleRecordObs} disabled={recording}>
              {recording ? 'Saving…' : 'Record Observations'}
            </Button>
          </div>
        </div>
      </Dialog>

      {/* Notes Dialog */}
      <Dialog open={!!showNotesDialog} onClose={() => setShowNotesDialog(null)} title="ICU Clinical Notes">
        <div className="dialog-form">
          <Select label="Note Type" value={noteType} onChange={(e) => setNoteType(e.target.value)}>
            <option value="progress">Progress Note</option>
            <option value="nursing">Nursing Note</option>
            <option value="consultation">Consultation</option>
            <option value="procedure">Procedure Note</option>
            <option value="handover">Handover Note</option>
          </Select>
          <div className="form-field">
            <label className="form-label">Content</label>
            <textarea
              className="form-textarea"
              rows={6}
              value={noteContent}
              onChange={(e) => setNoteContent(e.target.value)}
              placeholder="Clinical notes…"
            />
          </div>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={() => setShowNotesDialog(null)}>Cancel</Button>
            <Button onClick={handleSaveNote} disabled={savingNote || !noteContent.trim()}>
              {savingNote ? 'Saving…' : 'Save Note'}
            </Button>
          </div>
        </div>
      </Dialog>

      {/* Transfer Dialog */}
      <Dialog open={!!showTransferDialog} onClose={() => setShowTransferDialog(null)} title="Transfer Out from ICU">
        <div className="dialog-form">
          <Input label="Handover Notes" value={transferNotes} onChange={(e) => setTransferNotes(e.target.value)} placeholder="Transfer handover notes" />
          <div className="dialog-actions">
            <Button variant="secondary" onClick={() => setShowTransferDialog(null)}>Cancel</Button>
            <Button onClick={handleTransfer} disabled={transferring}>
              {transferring ? 'Transferring…' : 'Confirm Transfer'}
            </Button>
          </div>
        </div>
      </Dialog>

      {/* Detail / Quick Actions Dialog */}
      <Dialog open={!!showDetailDialog} onClose={() => setShowDetailDialog(null)} title="ICU Bed Actions">
        <div className="dialog-form">
          <p style={{ marginBottom: '1rem', color: 'var(--color-muted)' }}>
            Select an action for this bed:
          </p>
          <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
            <Button onClick={() => { setShowDetailDialog(null); setShowObsDialog(showDetailDialog); }}>Record Observations</Button>
            <Button variant="secondary" onClick={() => { setShowDetailDialog(null); setShowNotesDialog(showDetailDialog); }}>Add Notes</Button>
            <Button variant="secondary" onClick={() => { setShowDetailDialog(null); setShowTransferDialog(showDetailDialog); }}>Transfer</Button>
          </div>
          <div className="dialog-actions">
            <Button variant="secondary" onClick={() => setShowDetailDialog(null)}>Close</Button>
          </div>
        </div>
      </Dialog>
    </div>
  );
}
