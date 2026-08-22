import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { radiologyApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import './radiology.css';

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
  cancelReason: string | null;
  preparationInstructions: string | null;
  lockVersion: number;
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

interface Stats {
  pending: number;
  scheduled: number;
  performed: number;
  reported: number;
  cancelled: number;
  critical_pending: number;
}

interface Modality {
  id: string;
  code: string;
  name: string;
  status: string;
}

/* ── Constants ───────────────────────────────────────────────────── */

const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  ordered: { label: 'Ordered', color: '#f59e0b', bg: '#fef3c7' },
  scheduled: { label: 'Scheduled', color: '#3b82f6', bg: '#dbeafe' },
  arrived: { label: 'Arrived', color: '#8b5cf6', bg: '#f5f3ff' },
  in_progress: { label: 'In Progress', color: '#ea580c', bg: '#ffedd5' },
  performed: { label: 'Acquired', color: '#10b981', bg: '#ecfdf5' },
  reported: { label: 'Reported', color: '#059669', bg: '#ecfdf5' },
  cancelled: { label: 'Cancelled', color: '#6b7280', bg: '#f3f4f6' },
};

const REPORT_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  draft: { label: 'Draft', color: '#f59e0b', bg: '#fef3c7' },
  preliminary: { label: 'Preliminary', color: '#3b82f6', bg: '#dbeafe' },
  final: { label: 'Final', color: '#10b981', bg: '#ecfdf5' },
  verified: { label: 'Verified', color: '#059669', bg: '#ecfdf5' },
  amended: { label: 'Amended', color: '#ea580c', bg: '#ffedd5' },
};

const REPORT_TYPES = [
  { value: 'preliminary', label: 'Preliminary' },
  { value: 'final', label: 'Final' },
];

const MODALITY_ICONS: Record<string, string> = {
  'X-RAY': '☢', CT: '◎', MRI: '◉', US: '◔', MG: '◍', FL: '◐', NM: '◑', IR: '◒',
};

/* ── Main Component ──────────────────────────────────────────────── */

export function RadiologyPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [studies, setStudies] = useState<Study[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [modalities, setModalities] = useState<Modality[]>([]);
  const [selectedStudy, setSelectedStudy] = useState<Study | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [activeTab, setActiveTab] = useState<'worklist' | 'modalities' | 'history'>('worklist');
  const [filterStatus, setFilterStatus] = useState('');
  const [filterModality, setFilterModality] = useState('');

  // Dialog state
  const [dlg, setDlg] = useState<string | null>(null);

  // Report form
  const [reportForm, setReportForm] = useState({ content: '', impression: '', criticalFindings: '', reportType: 'preliminary' });

  // Schedule dialog
  const [showScheduleDlg, setShowScheduleDlg] = useState(false);
  const [schedModality, setSchedModality] = useState('');
  const [schedTime, setSchedTime] = useState('');

  // Modality dialog

  const loadData = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const [queueRes, statsRes] = await Promise.all([
        radiologyApi.queue(fac),
        radiologyApi.stats(fac),
      ]);
      setStudies(((queueRes as unknown as Record<string, unknown>)?.data as Study[]) || []);
      setStats(statsRes as unknown as Stats);
      // Load modalities
      try {
        const mods = await radiologyApi.modalities(fac);
        setModalities(((mods as unknown as Record<string, unknown>)?.data as Modality[]) || []);
      } catch { /* modalities optional */ }
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to load');
    } finally { setLoading(false); }
  }, [fac]);

  useEffect(() => { void loadData(); }, [loadData]);

  const handlePerform = useCallback(async (studyId: string) => {
    setBusy(true); setError(null);
    try { await radiologyApi.perform(studyId, {}, fac); await loadData(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); } finally { setBusy(false); }
  }, [fac, loadData]);

  const handleDraftReport = useCallback(async (studyId: string) => {
    if (!reportForm.content.trim()) { setError('Report content is required.'); return; }
    setBusy(true); setError(null);
    try {
      await radiologyApi.draftReport(studyId, { content: reportForm.content, reportType: reportForm.reportType }, fac);
      setReportForm({ content: '', impression: '', criticalFindings: '', reportType: 'preliminary' });
      setSelectedStudy(null); await loadData();
    } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); } finally { setBusy(false); }
  }, [reportForm, fac, loadData]);

  const handleSchedule = useCallback(async (studyId: string) => {
    if (!schedModality || !schedTime) { setError('Modality and time are required.'); return; }
    setBusy(true); setError(null);
    try {
      await radiologyApi.schedule(studyId, { modalityId: schedModality, scheduledAt: new Date(schedTime).toISOString() }, fac);
      setShowScheduleDlg(false); await loadData();
    } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); } finally { setBusy(false); }
  }, [schedModality, schedTime, fac, loadData]);


  // Census
  const census = useMemo(() => {
    const total = studies.length;
    const ordered = studies.filter(s => s.status === 'ordered').length;
    const scheduled = studies.filter(s => s.status === 'scheduled').length;
    const inProgress = studies.filter(s => s.status === 'in_progress' || s.status === 'performed').length;
    const reported = studies.filter(s => s.status === 'reported').length;
    const critical = stats?.critical_pending ?? 0;
    return { total, ordered, scheduled, inProgress, reported, critical };
  }, [studies, stats]);

  // Filtered studies
  const filteredStudies = useMemo(() => {
    let result = studies;
    if (filterStatus) result = result.filter(s => s.status === filterStatus);
    if (filterModality) result = result.filter(s => s.modalityId === filterModality);
    return result;
  }, [studies, filterStatus, filterModality]);

  if (loading) return <SkeletonTable rows={6} cols={5} />;

  return (
    <div className="page rad-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Radiology Information System</h1>
          <p className="page__subtitle">Imaging worklist, scheduling, reporting, and critical findings</p>
        </div>
        <div className="rad-actions">
          <Button variant="ghost" onClick={() => void loadData()}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="rad-census">
        <div className="rad-census-card rad-census-card--total">
          <span className="rad-census-value">{census.total}</span>
          <span className="rad-census-label">Total Studies</span>
        </div>
        <div className="rad-census-card rad-census-card--ordered">
          <span className="rad-census-value">{census.ordered}</span>
          <span className="rad-census-label">Awaiting Schedule</span>
        </div>
        <div className="rad-census-card rad-census-card--scheduled">
          <span className="rad-census-value">{census.scheduled}</span>
          <span className="rad-census-label">Scheduled</span>
        </div>
        <div className="rad-census-card rad-census-card--progress">
          <span className="rad-census-value">{census.inProgress}</span>
          <span className="rad-census-label">In Progress</span>
        </div>
        <div className="rad-census-card rad-census-card--reported">
          <span className="rad-census-value">{census.reported}</span>
          <span className="rad-census-label">Reported</span>
        </div>
        <div className="rad-census-card rad-census-card--critical">
          <span className="rad-census-value" style={{ color: census.critical > 0 ? '#dc2626' : undefined }}>
            {census.critical}
          </span>
          <span className="rad-census-label">Critical Findings</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="rad-tabs">
        <button className={`rad-tab ${activeTab === 'worklist' ? 'rad-tab--active' : ''}`} onClick={() => setActiveTab('worklist')}>
          Worklist
        </button>
        <button className={`rad-tab ${activeTab === 'modalities' ? 'rad-tab--active' : ''}`} onClick={() => setActiveTab('modalities')}>
          Modalities
        </button>
        <button className={`rad-tab ${activeTab === 'history' ? 'rad-tab--active' : ''}`} onClick={() => setActiveTab('history')}>
          Patient History
        </button>
      </div>

      {/* ── Worklist Tab ──────────────────────────────────── */}
      {activeTab === 'worklist' && (
        <Card className="rad-section-card">
          <div className="rad-section-header">
            <h3>Imaging Worklist</h3>
            <div className="rad-section-actions">
              <Select label="Status" value={filterStatus} onChange={e => setFilterStatus(e.target.value)}>
                <option value="">All Statuses</option>
                {Object.entries(STATUS_CONFIG).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
              </Select>
              <Select label="Modality" value={filterModality} onChange={e => setFilterModality(e.target.value)}>
                <option value="">All Modalities</option>
                {modalities.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
              </Select>
            </div>
          </div>
          {filteredStudies.length === 0 ? (
            <EmptyState title="No imaging studies" body="Orders from clinicians appear here." />
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Study</th>
                  <th>Patient</th>
                  <th>Modality</th>
                  <th>Status</th>
                  <th>Scheduled</th>
                  <th>Reports</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredStudies.map(study => {
                  const statusCfg = STATUS_CONFIG[study.status] ?? STATUS_CONFIG.ordered;
                  const lastReport = study.reports?.[study.reports.length - 1];
                  return (
                    <tr key={study.id}>
                      <td className="font-medium">{study.id.slice(0, 8)}...</td>
                      <td>
                        <Link to={`/patients/${study.patientId ?? ''}`} className="rad-patient-link">
                          {(study.patientId ?? '').slice(0, 8)}...
                        </Link>
                      </td>
                      <td>
                        <span className="rad-modality-badge">
                          {study.modality?.code ?? '—'}
                        </span>
                      </td>
                      <td>
                        <span className="rad-status-badge" style={{ color: statusCfg.color, backgroundColor: statusCfg.bg }}>
                          {statusCfg.label}
                        </span>
                      </td>
                      <td className="rad-time">
                        {study.scheduledAt ? new Date(study.scheduledAt).toLocaleString() : '—'}
                      </td>
                      <td>
                        {lastReport ? (
                          <span className="rad-report-badge" style={{
                            color: (REPORT_STATUS[lastReport.status] ?? REPORT_STATUS.draft).color,
                            backgroundColor: (REPORT_STATUS[lastReport.status] ?? REPORT_STATUS.draft).bg,
                          }}>
                            {lastReport.reportType}
                          </span>
                        ) : '—'}
                      </td>
                      <td>
                        <div className="rad-row-actions">
                          {study.status === 'ordered' && (
                            <Button variant="ghost" size="sm" onClick={() => { setSchedModality(''); setSchedTime(''); setShowScheduleDlg(true); setSelectedStudy(study); }}>
                              Schedule
                            </Button>
                          )}
                          {(study.status === 'scheduled' || study.status === 'arrived') && (
                            <Button variant="ghost" size="sm" onClick={() => void handlePerform(study.id)}>
                              Perform
                            </Button>
                          )}
                          {study.status === 'performed' && (
                            <Button variant="ghost" size="sm" onClick={() => { setReportForm({ content: '', impression: '', criticalFindings: '', reportType: 'preliminary' }); setSelectedStudy(study); setDlg('report'); }}>
                              Report
                            </Button>
                          )}
                          <Button variant="ghost" size="sm" onClick={() => setSelectedStudy(study)}>
                            View
                          </Button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Modalities Tab ────────────────────────────────── */}
      {activeTab === 'modalities' && (
        <Card className="rad-section-card">
          <div className="rad-section-header">
            <h3>Imaging Modalities</h3>
            <Button variant="ghost" size="sm">Add Modality</Button>
          </div>
          {modalities.length === 0 ? (
            <EmptyState title="No modalities configured" body="Configure imaging modalities in administration." />
          ) : (
            <div className="rad-modality-grid">
              {modalities.map(m => (
                <div key={m.id} className="rad-modality-card">
                  <span className="rad-modality-icon">{MODALITY_ICONS[m.code] ?? '◎'}</span>
                  <span className="rad-modality-name">{m.name}</span>
                  <span className="rad-modality-code">{m.code}</span>
                  <span className="rad-modality-status" style={{ color: m.status === 'active' ? '#10b981' : '#6b7280' }}>
                    {m.status}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Patient History Tab ───────────────────────────── */}
      {activeTab === 'history' && (
        <Card className="rad-section-card">
          <div className="rad-section-header">
            <h3>Patient Imaging History</h3>
          </div>
          <EmptyState title="Patient imaging history" body="Select a patient from the worklist to view their imaging history." />
        </Card>
      )}

      {/* ── Study Detail Dialog ───────────────────────────── */}
      {selectedStudy && !showScheduleDlg && (
        <Dialog open onClose={() => setSelectedStudy(null)} title="Imaging Study" footer={
          <Button variant="ghost" onClick={() => setSelectedStudy(null)}>Close</Button>
        }>
          <div className="rad-detail">
            <div className="rad-detail-row">
              <span className="rad-detail-label">Study ID</span>
              <span>{selectedStudy.id.slice(0, 12)}...</span>
            </div>
            <div className="rad-detail-row">
              <span className="rad-detail-label">Patient</span>
              <span>{(selectedStudy.patientId ?? '').slice(0, 12)}...</span>
            </div>
            <div className="rad-detail-row">
              <span className="rad-detail-label">Status</span>
              <span className="rad-status-badge" style={{
                color: (STATUS_CONFIG[selectedStudy.status] ?? STATUS_CONFIG.ordered).color,
                backgroundColor: (STATUS_CONFIG[selectedStudy.status] ?? STATUS_CONFIG.ordered).bg,
              }}>
                {(STATUS_CONFIG[selectedStudy.status] ?? STATUS_CONFIG.ordered).label}
              </span>
            </div>
            <div className="rad-detail-row">
              <span className="rad-detail-label">Modality</span>
              <span>{selectedStudy.modality?.name ?? '—'}</span>
            </div>
            <div className="rad-detail-row">
              <span className="rad-detail-label">Ordered</span>
              <span>{selectedStudy.orderedAt ? new Date(selectedStudy.orderedAt).toLocaleString() : '—'}</span>
            </div>
            <div className="rad-detail-row">
              <span className="rad-detail-label">Scheduled</span>
              <span>{selectedStudy.scheduledAt ? new Date(selectedStudy.scheduledAt).toLocaleString() : '—'}</span>
            </div>
            <div className="rad-detail-row">
              <span className="rad-detail-label">Performed</span>
              <span>{selectedStudy.performedAt ? new Date(selectedStudy.performedAt).toLocaleString() : '—'}</span>
            </div>

            {/* Reports */}
            <h4>Reports</h4>
            {selectedStudy.reports?.length ? selectedStudy.reports.map(r => {
              const rCfg = REPORT_STATUS[r.status] ?? REPORT_STATUS.draft;
              return (
                <div key={r.id} className="rad-report-item">
                  <div className="rad-report-header">
                    <span className="rad-report-type">{r.reportType}</span>
                    <span className="rad-report-status" style={{ color: rCfg.color, backgroundColor: rCfg.bg }}>{rCfg.label}</span>
                  </div>
                  <p className="rad-report-content">{r.content}</p>
                  {r.impression && <p className="rad-report-impression"><strong>Impression:</strong> {r.impression}</p>}
                  {r.criticalFindings && (
                    <div className="rad-critical-finding">
                      <span className="rad-critical-label">CRITICAL FINDING</span>
                      <p>{r.criticalFindings}</p>
                    </div>
                  )}
                </div>
              );
            }) : <p className="rad-muted">No reports yet</p>}
          </div>
        </Dialog>
      )}

      {/* ── Schedule Dialog ───────────────────────────────── */}
      {showScheduleDlg && (
        <Dialog open onClose={() => setShowScheduleDlg(false)} title="Schedule Imaging Study" footer={
          <>
            <Button variant="ghost" onClick={() => setShowScheduleDlg(false)}>Cancel</Button>
            <Button onClick={() => selectedStudy && void handleSchedule(selectedStudy.id)} loading={busy}>Schedule</Button>
          </>
        }>
          <Select label="Modality" value={schedModality} onChange={e => setSchedModality(e.target.value)}>
            <option value="">Select modality...</option>
            {modalities.filter(m => m.status === 'active').map(m => (
              <option key={m.id} value={m.id}>{m.name} ({m.code})</option>
            ))}
          </Select>
          <Input label="Date & Time" type="datetime-local" value={schedTime} onChange={e => setSchedTime(e.target.value)} />
        </Dialog>
      )}

      {/* ── Report Dialog ─────────────────────────────────── */}
      {dlg === 'report' && selectedStudy && (
        <Dialog open onClose={() => setDlg(null)} title="Draft Radiology Report" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => void handleDraftReport(selectedStudy.id)} loading={busy}>Submit Report</Button>
          </>
        }>
          <Select label="Report Type" value={reportForm.reportType} onChange={e => setReportForm(prev => ({ ...prev, reportType: e.target.value }))}>
            {REPORT_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>
          <Input label="Findings" value={reportForm.content} onChange={e => setReportForm(prev => ({ ...prev, content: e.target.value }))} placeholder="Detailed radiological findings" />
          <Input label="Impression" value={reportForm.impression} onChange={e => setReportForm(prev => ({ ...prev, impression: e.target.value }))} placeholder="Clinical impression" />
          <Input label="Critical Findings" value={reportForm.criticalFindings} onChange={e => setReportForm(prev => ({ ...prev, criticalFindings: e.target.value }))} placeholder="Critical findings (if any)" />
          {reportForm.criticalFindings && <Alert tone="danger">Critical findings will trigger clinician notification.</Alert>}
        </Dialog>
      )}
    </div>
  );
}
