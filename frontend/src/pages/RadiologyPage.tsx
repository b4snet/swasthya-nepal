import { useState, useEffect } from 'react';
import { useTenant } from '../context/TenantContext';
import { radiologyApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Card, EmptyState, Spinner } from '../components/ui';

type Study = {
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
};

type Report = {
  id: string;
  studyId: string;
  reportType: string;
  status: string;
  content: string;
  impression: string | null;
  criticalFindings: string | null;
  reportedAt: string | null;
  verifiedAt: string | null;
};

type ImageRef = {
  id: string;
  referenceType: string;
  referenceValue: string;
  description: string | null;
};

type Stats = {
  pending: number;
  scheduled: number;
  performed: number;
  reported: number;
  cancelled: number;
  critical_pending: number;
};

const STATUS_COLORS: Record<string, string> = {
  ordered: '#f59e0b',
  scheduled: '#3b82f6',
  performed: '#8b5cf6',
  reported: '#10b981',
  cancelled: '#6b7280',
};

const STATUS_LABELS: Record<string, string> = {
  ordered: 'Ordered',
  scheduled: 'Scheduled',
  performed: 'Performed',
  reported: 'Reported',
  cancelled: 'Cancelled',
};

export function RadiologyPage() {
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;

  const [activeTab, setActiveTab] = useState<'worklist' | 'history'>('worklist');
  const [studies, setStudies] = useState<Study[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [selectedStudy, setSelectedStudy] = useState<Study | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [filterStatus, setFilterStatus] = useState('');

  // Report form
  const [reportForm, setReportForm] = useState({ content: '', impression: '', criticalFindings: '', reportType: 'preliminary' });

  useEffect(() => {
    loadData();
  }, [activeTab, fac]);

  async function loadData() {
    setLoading(true);
    setError(null);
    try {
      if (activeTab === 'worklist') {
        const [queueRes, statsRes] = await Promise.all([
          radiologyApi.queue(fac),
          radiologyApi.stats(fac),
        ]);
        setStudies((queueRes as any)?.data || []);
        setStats(statsRes as any);
      }
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to load data');
    } finally {
      setLoading(false);
    }
  }

  async function handlePerform(studyId: string) {
    setBusy(true);
    try {
      await radiologyApi.perform(studyId, {}, fac);
      loadData();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to perform');
    } finally {
      setBusy(false);
    }
  }

  async function handleDraftReport(studyId: string) {
    if (!reportForm.content) return;
    setBusy(true);
    try {
      await radiologyApi.draftReport(studyId, {
        content: reportForm.content,
        reportType: reportForm.reportType,
      }, fac);
      setReportForm({ content: '', impression: '', criticalFindings: '', reportType: 'preliminary' });
      setSelectedStudy(null);
      loadData();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to draft report');
    } finally {
      setBusy(false);
    }
  }

  const filteredStudies = filterStatus
    ? studies.filter((s) => s.status === filterStatus)
    : studies;

  return (
    <div className="page">
      <h1>🩻 Radiology</h1>

      {error && <div className="alert alert-error" role="alert">{error}</div>}

      {/* Stats Bar */}
      {stats && (
        <div className="stats-bar">
          <div className="stat-card">
            <span className="stat-value" style={{ color: '#f59e0b' }}>{stats.pending}</span>
            <span className="stat-label">Pending</span>
          </div>
          <div className="stat-card">
            <span className="stat-value" style={{ color: '#3b82f6' }}>{stats.scheduled}</span>
            <span className="stat-label">Scheduled</span>
          </div>
          <div className="stat-card">
            <span className="stat-value" style={{ color: '#8b5cf6' }}>{stats.performed}</span>
            <span className="stat-label">Performed</span>
          </div>
          <div className="stat-card">
            <span className="stat-value" style={{ color: '#10b981' }}>{stats.reported}</span>
            <span className="stat-label">Reported</span>
          </div>
          {stats.critical_pending > 0 && (
            <div className="stat-card" style={{ border: '2px solid #ef4444' }}>
              <span className="stat-value" style={{ color: '#ef4444' }}>{stats.critical_pending}</span>
              <span className="stat-label">Critical Pending</span>
            </div>
          )}
        </div>
      )}

      {/* Tab Navigation */}
      <div className="tab-nav">
        <button className={`tab ${activeTab === 'worklist' ? 'active' : ''}`} onClick={() => setActiveTab('worklist')}>
          📋 Worklist
        </button>
        <button className={`tab ${activeTab === 'history' ? 'active' : ''}`} onClick={() => setActiveTab('history')}>
          📜 Imaging History
        </button>
      </div>

      {/* Worklist Tab */}
      {activeTab === 'worklist' && (
        <div>
          <div className="flex justify-between items-center mb-4">
            <h2>Radiology Worklist</h2>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} aria-label="Filter by status">
              <option value="">All Statuses</option>
              <option value="ordered">Ordered</option>
              <option value="scheduled">Scheduled</option>
              <option value="performed">Performed (Awaiting Report)</option>
              <option value="reported">Reported</option>
            </select>
          </div>

          {loading ? (
            <Spinner />
          ) : filteredStudies.length === 0 ? (
            <EmptyState title="No studies" body="No imaging studies match the current filter." />
          ) : (
            <div className="table-responsive">
              <table>
                <thead>
                  <tr>
                    <th>Status</th>
                    <th>Modality</th>
                    <th>Ordered</th>
                    <th>Scheduled</th>
                    <th>Performed</th>
                    <th>Reports</th>
                    <th>Images</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredStudies.map((s) => (
                    <tr key={s.id} onClick={() => setSelectedStudy(s)} style={{ cursor: 'pointer' }}>
                      <td>
                        <span className="badge" style={{ backgroundColor: STATUS_COLORS[s.status] || '#6b7280', color: 'white', padding: '2px 8px', borderRadius: 4 }}>
                          {STATUS_LABELS[s.status] || s.status}
                        </span>
                      </td>
                      <td>{s.modality?.name || s.modality?.code || '—'}</td>
                      <td>{s.orderedAt ? new Date(s.orderedAt).toLocaleDateString() : '—'}</td>
                      <td>{s.scheduledAt ? new Date(s.scheduledAt).toLocaleString() : '—'}</td>
                      <td>{s.performedAt ? new Date(s.performedAt).toLocaleString() : '—'}</td>
                      <td>{s.reports?.length || 0}</td>
                      <td>{s.imageReferences?.length || 0}</td>
                      <td>
                        {s.status === 'ordered' && (
                          <button className="btn btn-sm btn-primary" onClick={(e) => { e.stopPropagation(); handlePerform(s.id); }} disabled={busy}>
                            Perform
                          </button>
                        )}
                        {s.status === 'performed' && (
                          <button className="btn btn-sm btn-success" onClick={(e) => { e.stopPropagation(); setSelectedStudy(s); }} disabled={busy}>
                            Report
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Imaging History Tab */}
      {activeTab === 'history' && (
        <div>
          <h2>Imaging History</h2>
          <p className="text-muted">Longitudinal imaging history for all patients in this facility.</p>
          {loading ? (
            <Spinner />
          ) : (
            <div className="table-responsive">
              <table>
                <thead>
                  <tr>
                    <th>Status</th>
                    <th>Modality</th>
                    <th>Ordered</th>
                    <th>Reports</th>
                    <th>Images</th>
                  </tr>
                </thead>
                <tbody>
                  {studies.map((s) => (
                    <tr key={s.id}>
                      <td>
                        <span className="badge" style={{ backgroundColor: STATUS_COLORS[s.status] || '#6b7280', color: 'white', padding: '2px 8px', borderRadius: 4 }}>
                          {STATUS_LABELS[s.status] || s.status}
                        </span>
                      </td>
                      <td>{s.modality?.name || '—'}</td>
                      <td>{s.orderedAt ? new Date(s.orderedAt).toLocaleDateString() : '—'}</td>
                      <td>{s.reports?.length || 0}</td>
                      <td>{s.imageReferences?.length || 0}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Study Detail Dialog */}
      {selectedStudy && (
        <div className="dialog-overlay" onClick={() => setSelectedStudy(null)}>
          <div className="dialog" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" style={{ maxWidth: 800 }}>
            <h3>Study Detail — {selectedStudy.modality?.name || 'Unknown Modality'}</h3>

            <div className="card mb-4">
              <div className="form-row">
                <div><strong>Status:</strong> {STATUS_LABELS[selectedStudy.status]}</div>
                <div><strong>Ordered:</strong> {selectedStudy.orderedAt ? new Date(selectedStudy.orderedAt).toLocaleString() : '—'}</div>
                <div><strong>Scheduled:</strong> {selectedStudy.scheduledAt ? new Date(selectedStudy.scheduledAt).toLocaleString() : '—'}</div>
                <div><strong>Performed:</strong> {selectedStudy.performedAt ? new Date(selectedStudy.performedAt).toLocaleString() : '—'}</div>
              </div>
              {selectedStudy.preparationInstructions && (
                <div><strong>Preparation:</strong> {selectedStudy.preparationInstructions}</div>
              )}
            </div>

            {/* Existing Reports */}
            {selectedStudy.reports && selectedStudy.reports.length > 0 && (
              <div className="mb-4">
                <h4>Reports</h4>
                {selectedStudy.reports.map((r) => (
                  <Card key={r.id}>
                    <div className="flex justify-between items-center">
                      <span className="badge" style={{ backgroundColor: r.status === 'final' ? '#10b981' : '#f59e0b', color: 'white', padding: '2px 8px', borderRadius: 4 }}>
                        {r.status}
                      </span>
                      <span className="text-muted">{r.reportType}</span>
                    </div>
                    <p style={{ marginTop: 8 }}>{r.content}</p>
                    {r.impression && <p><strong>Impression:</strong> {r.impression}</p>}
                    {r.criticalFindings && (
                      <div className="alert alert-warning" style={{ marginTop: 8 }}>
                        ⚠️ <strong>Critical Findings:</strong> {r.criticalFindings}
                      </div>
                    )}
                    <div className="text-muted" style={{ fontSize: 12 }}>
                      Reported: {r.reportedAt ? new Date(r.reportedAt).toLocaleString() : '—'}
                      {r.verifiedAt && ` | Verified: ${new Date(r.verifiedAt).toLocaleString()}`}
                    </div>
                  </Card>
                ))}
              </div>
            )}

            {/* Image References */}
            {selectedStudy.imageReferences && selectedStudy.imageReferences.length > 0 && (
              <div className="mb-4">
                <h4>DICOM References</h4>
                <div className="table-responsive">
                  <table>
                    <thead>
                      <tr>
                        <th>Type</th>
                        <th>Value</th>
                        <th>Description</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedStudy.imageReferences.map((ref) => (
                        <tr key={ref.id}>
                          <td><code>{ref.referenceType}</code></td>
                          <td><code>{ref.referenceValue}</code></td>
                          <td>{ref.description || '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* Report Form (for performed studies) */}
            {selectedStudy.status === 'performed' && (
              <div>
                <h4>Draft Report</h4>
                <div className="form-group">
                  <label htmlFor="report-type">Report Type</label>
                  <select id="report-type" value={reportForm.reportType} onChange={(e) => setReportForm({ ...reportForm, reportType: e.target.value })}>
                    <option value="preliminary">Preliminary</option>
                    <option value="final">Final</option>
                  </select>
                </div>
                <div className="form-group">
                  <label htmlFor="report-content">Report Content *</label>
                  <textarea id="report-content" value={reportForm.content} onChange={(e) => setReportForm({ ...reportForm, content: e.target.value })} rows={6} placeholder="Enter radiology report findings..." />
                </div>
                <div className="form-group">
                  <label htmlFor="report-impression">Impression</label>
                  <textarea id="report-impression" value={reportForm.impression} onChange={(e) => setReportForm({ ...reportForm, impression: e.target.value })} rows={3} />
                </div>
                <div className="form-group">
                  <label htmlFor="report-critical">Critical Findings</label>
                  <textarea id="report-critical" value={reportForm.criticalFindings} onChange={(e) => setReportForm({ ...reportForm, criticalFindings: e.target.value })} rows={2} placeholder="Document any critical findings requiring immediate attention..." />
                </div>
                <button className="btn btn-primary" onClick={() => handleDraftReport(selectedStudy.id)} disabled={busy || !reportForm.content}>
                  {busy ? 'Submitting...' : 'Submit Report'}
                </button>
              </div>
            )}

            <div className="dialog-actions">
              <button className="btn" onClick={() => setSelectedStudy(null)}>Close</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
