import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { radiologyApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import './pacs.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface Study {
  id: string;
  facilityId: string;
  orderId: string;
  modalityId: string;
  status: string;
  orderedAt: string | null;
  scheduledAt: string | null;
  performedAt: string | null;
  modality: { id: string; code: string; name: string } | null;
  reports: Report[];
  imageReferences: ImageRef[];
  patientId?: string;
}

interface Report {
  id: string;
  studyId: string;
  reportType: string;
  status: string;
  content: string;
  impression: string | null;
  criticalFindings: string | null;
  reportedAt: string | null;
  verifiedAt: string | null;
}

interface ImageRef {
  id: string;
  referenceType: string;
  referenceValue: string;
  description: string | null;
}

/* ── Constants ───────────────────────────────────────────────────── */

const DICOM_TYPES = [
  { value: 'dicom_study_instance_uid', label: 'DICOM Study UID' },
  { value: 'dicom_series_instance_uid', label: 'DICOM Series UID' },
  { value: 'dicom_sop_instance_uid', label: 'DICOM SOP Instance UID' },
  { value: 'pacs_url', label: 'PACS URL' },
];

const STATUS_COLORS: Record<string, string> = {
  ordered: '#f59e0b',
  scheduled: '#3b82f6',
  performed: '#10b981',
  reported: '#059669',
  cancelled: '#6b7280',
};

/* ── Main Component ──────────────────────────────────────────────── */

export function PacsViewer() {
  const { studyId } = useParams<{ studyId: string }>();
  const { selectedFacilityId: fac } = useTenant();
  const [study, setStudy] = useState<Study | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [showAddRefDlg, setShowAddRefDlg] = useState(false);

  // Add reference form
  const [refType, setRefType] = useState('dicom_study_instance_uid');
  const [refValue, setRefValue] = useState('');
  const [refDesc, setRefDesc] = useState('');

  const loadStudy = useCallback(async () => {
    if (!studyId) return;
    setLoading(true); setError(null);
    try {
      const data = await radiologyApi.showStudy(studyId, fac);
      setStudy(data as unknown as Study);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to load study');
    } finally { setLoading(false); }
  }, [studyId, fac]);

  useEffect(() => { void loadStudy(); }, [loadStudy]);

  const handleAddReference = useCallback(async () => {
    if (!studyId || !refValue.trim()) return;
    setBusy(true); setError(null);
    try {
      await fetch(`/api/v1/studies/${studyId}/image-references`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${localStorage.getItem('token')}` },
        body: JSON.stringify({ references: [{ referenceType: refType, referenceValue: refValue.trim(), description: refDesc.trim() || undefined }] }),
      });
      setShowAddRefDlg(false);
      setRefValue(''); setRefDesc('');
      void loadStudy();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to add reference');
    } finally { setBusy(false); }
  }, [studyId, refType, refValue, refDesc, loadStudy]);

  // Group image references by type
  const refsByType = useMemo(() => {
    const groups: Record<string, ImageRef[]> = {};
    (study?.imageReferences ?? []).forEach(ref => {
      if (!groups[ref.referenceType]) groups[ref.referenceType] = [];
      groups[ref.referenceType].push(ref);
    });
    return groups;
  }, [study]);

  const studyUid = refsByType['dicom_study_instance_uid']?.[0]?.referenceValue;
  const pacsUrl = refsByType['pacs_url']?.[0]?.referenceValue;

  if (loading) return <SkeletonTable rows={6} cols={3} />;
  if (error) return <Alert tone="danger">{error}</Alert>;
  if (!study) return <EmptyState title="Study not found" body="The requested imaging study could not be loaded." />;

  const lastReport = study.reports?.[study.reports.length - 1];

  return (
    <div className="page pacs-page">
      {/* ── Patient Context Header ──────────────────────── */}
      <div className="pacs-context-header">
        <div className="pacs-context-info">
          <Link to={`/patients/${study.patientId ?? ''}`} className="pacs-patient-link">
            Patient: {(study.patientId ?? '').slice(0, 12)}...
          </Link>
          <span className="pacs-context-sep">|</span>
          <span className="pacs-context-study">Study: {study.id.slice(0, 12)}...</span>
          <span className="pacs-context-sep">|</span>
          <span className="pacs-context-modality">{study.modality?.name ?? '—'}</span>
          <span className="pacs-context-sep">|</span>
          <span className="pacs-context-status" style={{ color: STATUS_COLORS[study.status] ?? '#6b7280' }}>
            {study.status}
          </span>
        </div>
        <div className="pacs-context-actions">
          <Button variant="ghost" size="sm" onClick={() => void loadStudy()}>Refresh</Button>
          <Link to="/radiology">
            <Button variant="ghost" size="sm">← Back to RIS</Button>
          </Link>
        </div>
      </div>

      {/* ── Split View ──────────────────────────────────── */}
      <div className="pacs-split">
        {/* Image Panel */}
        <div className="pacs-image-panel">
          <div className="pacs-image-header">
            <h3>Imaging Study</h3>
            <Button variant="ghost" size="sm" onClick={() => setShowAddRefDlg(true)}>
              + Add DICOM Reference
            </Button>
          </div>

          {/* Image References */}
          {study.imageReferences?.length ? (
            <div className="pacs-refs-section">
              {Object.entries(refsByType).map(([type, refs]) => (
                <div key={type} className="pacs-ref-group">
                  <span className="pacs-ref-type">{type.replace(/_/g, ' ')}</span>
                  {refs.map(ref => (
                    <div key={ref.id} className="pacs-ref-item">
                      <code className="pacs-ref-value">{ref.referenceValue}</code>
                      {ref.description && <span className="pacs-ref-desc">{ref.description}</span>}
                    </div>
                  ))}
                </div>
              ))}

              {/* PACS Viewer Launch */}
              {pacsUrl && (
                <div className="pacs-viewer-launch">
                  <h4>PACS Viewer</h4>
                  <a href={pacsUrl} target="_blank" rel="noopener noreferrer" className="pacs-viewer-link">
                    Open in PACS Viewer →
                  </a>
                  <p className="pacs-viewer-note">Opens the external PACS viewer for this study.</p>
                </div>
              )}

              {studyUid && (
                <div className="pacs-study-uid">
                  <span className="pacs-uid-label">DICOM Study Instance UID:</span>
                  <code className="pacs-uid-value">{studyUid}</code>
                </div>
              )}
            </div>
          ) : (
            <div className="pacs-no-images">
              <EmptyState
                title="No DICOM references"
                body="This study has no linked DICOM/PACS image references yet. Add references after image acquisition."
              />
            </div>
          )}

          {/* Image Viewer Placeholder */}
          {pacsUrl ? (
            <div className="pacs-viewer-frame">
              <div className="pacs-viewer-placeholder">
                <span className="pacs-viewer-icon">◎</span>
                <p>PACS Viewer Integration</p>
                <p className="pacs-viewer-sub">Image viewing is provided through the configured external PACS system.</p>
                <a href={pacsUrl} target="_blank" rel="noopener noreferrer">
                  <Button variant="primary" size="sm">Launch External Viewer</Button>
                </a>
              </div>
            </div>
          ) : (
            <div className="pacs-viewer-frame">
              <div className="pacs-viewer-placeholder">
                <span className="pacs-viewer-icon">⊘</span>
                <p>No PACS Viewer Configured</p>
                <p className="pacs-viewer-sub">Configure a PACS endpoint in administration to enable image viewing.</p>
              </div>
            </div>
          )}
        </div>

        {/* Report Panel */}
        <div className="pacs-report-panel">
          <div className="pacs-report-header">
            <h3>Radiology Report</h3>
          </div>

          {lastReport ? (
            <div className="pacs-report-content">
              <div className="pacs-report-meta">
                <span className="pacs-report-type">{lastReport.reportType}</span>
                <span className="pacs-report-status" style={{ color: STATUS_COLORS[lastReport.status] ?? '#6b7280' }}>
                  {lastReport.status}
                </span>
              </div>
              <div className="pacs-report-section">
                <h4>Findings</h4>
                <p>{lastReport.content}</p>
              </div>
              {lastReport.impression && (
                <div className="pacs-report-section">
                  <h4>Impression</h4>
                  <p>{lastReport.impression}</p>
                </div>
              )}
              {lastReport.criticalFindings && (
                <div className="pacs-critical-finding">
                  <span className="pacs-critical-label">CRITICAL FINDING</span>
                  <p>{lastReport.criticalFindings}</p>
                </div>
              )}
              <div className="pacs-report-timestamps">
                {lastReport.reportedAt && <span>Reported: {new Date(lastReport.reportedAt).toLocaleString()}</span>}
                {lastReport.verifiedAt && <span>Verified: {new Date(lastReport.verifiedAt).toLocaleString()}</span>}
              </div>
            </div>
          ) : (
            <EmptyState title="No report" body="Report will appear here after radiologist interpretation." />
          )}
        </div>
      </div>

      {/* ── Add Reference Dialog ────────────────────────── */}
      {showAddRefDlg && (
        <Dialog open onClose={() => setShowAddRefDlg(false)} title="Add DICOM/PACS Reference" footer={
          <>
            <Button variant="ghost" onClick={() => setShowAddRefDlg(false)}>Cancel</Button>
            <Button onClick={() => void handleAddReference()} loading={busy}>Add Reference</Button>
          </>
        }>
          <Select label="Reference Type" value={refType} onChange={e => setRefType(e.target.value)}>
            {DICOM_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>
          <Input label="Reference Value" value={refValue} onChange={e => setRefValue(e.target.value)} placeholder={refType.includes('uid') ? '1.2.840...' : 'https://pacs...'} />
          <Input label="Description (optional)" value={refDesc} onChange={e => setRefDesc(e.target.value)} placeholder="Optional description" />
          <Alert tone="info">References link RIS studies to DICOM/PACS images. Only performed studies can receive references.</Alert>
        </Dialog>
      )}
    </div>
  );
}
