import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { bedWardApi, erApi, admissionApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import './ipd.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface BedInfo {
  id: string;
  bedCode: string;
  status: string;
  lockVersion: number;
  admissionId: string | null;
}

interface WardInfo {
  id: string;
  name: string;
  wardType: string;
  counts: Record<string, number>;
  rooms: Array<{
    id: string;
    name: string;
    roomType: string;
    counts: Record<string, number>;
    beds: BedInfo[];
  }>;
}

interface ErQueueEntry {
  encounterId: string;
  patientId: string;
  facilityId: string;
  registeredAt: string | null;
  triageLevel: number | null;
  triageColor: string | null;
  presentingComplaint: string | null;
}

/* ── Constants ───────────────────────────────────────────────────── */

const BED_STATUS_COLORS: Record<string, string> = {
  available: '#10b981',
  occupied: '#ef4444',
  reserved: '#f59e0b',
  cleaning: '#3b82f6',
  maintenance: '#8b5cf6',
  out_of_service: '#6b7280',
};

/* ── Main Component ──────────────────────────────────────────────── */

export function IpdDashboard() {
  const { organizationId } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'census' | 'admissions' | 'transfers' | 'discharge'>('census');
  const [showAdmitDialog, setShowAdmitDialog] = useState(false);
  const [admitTarget, setAdmitTarget] = useState<ErQueueEntry | null>(null);

  const occupancy = useFetch(
    () => organizationId ? bedWardApi.occupancy(organizationId) : Promise.resolve({ summary: {} as Record<string, number>, wards: [] }),
    [organizationId],
  );

  const erQueue = useFetch(() => erApi.queue(), ['er-queue-for-admit']);

  const refreshAll = useCallback(() => {
    void occupancy.refresh();
    void erQueue.refresh();
  }, [occupancy, erQueue]);

  useEffect(() => {
    const id = setInterval(refreshAll, 30000);
    return () => clearInterval(id);
  }, [refreshAll]);

  if (occupancy.loading) return <SkeletonTable rows={6} cols={5} />;

  const summary = occupancy.data?.summary ?? {};
  const wards: WardInfo[] = occupancy.data?.wards ?? [];
  const totalBeds = (summary as Record<string, number>).total ?? 0;
  const occupiedBeds = (summary as Record<string, number>).occupied ?? 0;
  const availableBeds = (summary as Record<string, number>).available ?? 0;
  const occupancyRate = totalBeds > 0 ? Math.round((occupiedBeds / totalBeds) * 100) : 0;

  // Calculate pending transfers and discharge-ready
  const allBeds = wards.flatMap(w => w.rooms.flatMap(r => r.beds));
  const occupiedWithAdmission = allBeds.filter(b => b.status === 'occupied' && b.admissionId);
  const cleaningBeds = allBeds.filter(b => b.status === 'cleaning');

  return (
    <div className="page ipd-page">
      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Command Header ──────────────────────────────────── */}
      <header className="page__head">
        <div>
          <h1 className="page__title">Inpatient Department</h1>
          <p className="page__subtitle">Ward management, admissions, and patient flow</p>
        </div>
        <div className="ipd-actions">
          <Button variant="primary" onClick={() => setShowAdmitDialog(true)}>
            Admit Patient
          </Button>
          <Button variant="ghost" onClick={() => void refreshAll()}>
            Refresh
          </Button>
        </div>
      </header>

      {/* ── Census Dashboard ────────────────────────────────── */}
      <div className="ipd-census">
        <div className="ipd-census-card ipd-census-card--total">
          <span className="ipd-census-value">{totalBeds}</span>
          <span className="ipd-census-label">Total Beds</span>
        </div>
        <div className="ipd-census-card ipd-census-card--occupied">
          <span className="ipd-census-value">{occupiedBeds}</span>
          <span className="ipd-census-label">Occupied</span>
        </div>
        <div className="ipd-census-card ipd-census-card--available">
          <span className="ipd-census-value">{availableBeds}</span>
          <span className="ipd-census-label">Available</span>
        </div>
        <div className="ipd-census-card ipd-census-card--cleaning">
          <span className="ipd-census-value">{cleaningBeds.length}</span>
          <span className="ipd-census-label">Cleaning</span>
        </div>
        <div className="ipd-census-card ipd-census-card--rate">
          <span className="ipd-census-value">{occupancyRate}%</span>
          <span className="ipd-census-label">Occupancy</span>
          <div className="ipd-occupancy-bar">
            <div className="ipd-occupancy-fill" style={{ width: `${occupancyRate}%` }} />
          </div>
        </div>
      </div>

      {/* ── Tab Navigation ──────────────────────────────────── */}
      <div className="ipd-tabs">
        <button
          className={`ipd-tab ${activeTab === 'census' ? 'ipd-tab--active' : ''}`}
          onClick={() => setActiveTab('census')}
        >
          Ward Census
        </button>
        <button
          className={`ipd-tab ${activeTab === 'admissions' ? 'ipd-tab--active' : ''}`}
          onClick={() => setActiveTab('admissions')}
        >
          Admissions
        </button>
        <button
          className={`ipd-tab ${activeTab === 'transfers' ? 'ipd-tab--active' : ''}`}
          onClick={() => setActiveTab('transfers')}
        >
          Transfers
        </button>
        <button
          className={`ipd-tab ${activeTab === 'discharge' ? 'ipd-tab--active' : ''}`}
          onClick={() => setActiveTab('discharge')}
        >
          Discharge Planning
        </button>
      </div>

      {/* ── Ward Census Tab ─────────────────────────────────── */}
      {activeTab === 'census' && (
        <div className="ipd-ward-grid">
          {wards.length === 0 ? (
            <EmptyState title="No wards configured" body="Configure wards and beds in hospital administration." />
          ) : (
            wards.map(ward => {
              const wardOccupied = ward.counts.occupied ?? 0;
              const wardTotal = Object.values(ward.counts).reduce((s, n) => s + n, 0);
              const wardRate = wardTotal > 0 ? Math.round((wardOccupied / wardTotal) * 100) : 0;

              return (
                <Card key={ward.id} className="ipd-ward-card">
                  <div className="ipd-ward-header">
                    <div>
                      <h3 className="ipd-ward-name">{ward.name}</h3>
                      <span className="ipd-ward-type">{ward.wardType}</span>
                    </div>
                    <div className="ipd-ward-occupancy">
                      <span className="ipd-ward-rate">{wardRate}%</span>
                      <span className="ipd-ward-count">{wardOccupied}/{wardTotal}</span>
                    </div>
                  </div>

                  {/* Bed Grid */}
                  <div className="ipd-bed-grid">
                    {ward.rooms.flatMap(room =>
                      room.beds.map(bed => (
                        <div
                          key={bed.id}
                          className={`ipd-bed-cell ipd-bed-cell--${bed.status}`}
                          title={`${bed.bedCode} — ${bed.status}`}
                        >
                          <span className="ipd-bed-code">{bed.bedCode}</span>
                          <span
                            className="ipd-bed-dot"
                            style={{ backgroundColor: BED_STATUS_COLORS[bed.status] ?? '#9ca3af' }}
                          />
                        </div>
                      ))
                    )}
                  </div>
                </Card>
              );
            })
          )}
        </div>
      )}

      {/* ── Admissions Tab ──────────────────────────────────── */}
      {activeTab === 'admissions' && (
        <Card className="ipd-section-card">
          <div className="ipd-section-header">
            <h3>ER Patients Awaiting Admission</h3>
            <span className="ipd-badge">{erQueue.data?.length ?? 0}</span>
          </div>
          {(!erQueue.data || erQueue.data.length === 0) ? (
            <EmptyState title="No patients awaiting admission" body="All ER patients have been dispositioned." />
          ) : (
            <div className="ipd-patient-list">
              {erQueue.data.map(entry => (
                <div key={entry.encounterId} className="ipd-patient-row">
                  <div className="ipd-patient-info">
                    <Link to={`/patients/${entry.patientId}`} className="ipd-patient-link">
                      {entry.patientId.slice(0, 8)}...
                    </Link>
                    {entry.presentingComplaint && (
                      <span className="ipd-patient-complaint">{entry.presentingComplaint}</span>
                    )}
                  </div>
                  <div className="ipd-patient-meta">
                    {entry.triageLevel !== null && (
                      <span className="ipd-triage-badge" style={{ backgroundColor: entry.triageColor ?? '#6b7280' }}>
                        T{entry.triageLevel}
                      </span>
                    )}
                  </div>
                  <Button
                    variant="primary"
                    size="sm"
                    onClick={() => { setAdmitTarget(entry); setShowAdmitDialog(true); }}
                  >
                    Admit
                  </Button>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Transfers Tab ───────────────────────────────────── */}
      {activeTab === 'transfers' && (
        <Card className="ipd-section-card">
          <div className="ipd-section-header">
            <h3>Bed Status Overview</h3>
          </div>
          <div className="ipd-transfer-list">
            {allBeds.filter(b => b.status === 'reserved' || b.status === 'cleaning').length === 0 ? (
              <EmptyState title="No pending transfers" body="All beds are in their normal state." />
            ) : (
              allBeds.filter(b => b.status === 'reserved' || b.status === 'cleaning').map(bed => (
                <div key={bed.id} className="ipd-transfer-row">
                  <span className="ipd-bed-code">{bed.bedCode}</span>
                  <span
                    className="ipd-status-badge"
                    style={{
                      backgroundColor: BED_STATUS_COLORS[bed.status] + '15',
                      color: BED_STATUS_COLORS[bed.status],
                    }}
                  >
                    {bed.status}
                  </span>
                </div>
              ))
            )}
          </div>
        </Card>
      )}

      {/* ── Discharge Planning Tab ──────────────────────────── */}
      {activeTab === 'discharge' && (
        <Card className="ipd-section-card">
          <div className="ipd-section-header">
            <h3>Discharge Readiness</h3>
            <span className="ipd-badge">{occupiedWithAdmission.length}</span>
          </div>
          {occupiedWithAdmission.length === 0 ? (
            <EmptyState title="No occupied beds" body="No patients currently admitted." />
          ) : (
            <div className="ipd-patient-list">
              {occupiedWithAdmission.map(bed => (
                <div key={bed.id} className="ipd-patient-row">
                  <div className="ipd-patient-info">
                    <span className="ipd-bed-code">{bed.bedCode}</span>
                    <span className="ipd-patient-meta">
                      Admission: {bed.admissionId?.slice(0, 8)}...
                    </span>
                  </div>
                  <div className="ipd-discharge-status">
                    <span className="ipd-status-badge ipd-status-badge--purple">In Care</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Admission Dialog ────────────────────────────────── */}
      {showAdmitDialog && (
        <AdmissionDialog
          open
          target={admitTarget}
          wards={wards}
          onClose={() => { setShowAdmitDialog(false); setAdmitTarget(null); setError(null); }}
          onAdmitted={() => { setShowAdmitDialog(false); setAdmitTarget(null); void refreshAll(); }}
          onError={setError}
        />
      )}
    </div>
  );
}

/* ── Admission Dialog ────────────────────────────────────────────── */

function AdmissionDialog({ open, target, wards, onClose, onAdmitted, onError }: {
  open: boolean;
  target: ErQueueEntry | null;
  wards: WardInfo[];
  onClose: () => void;
  onAdmitted: () => void;
  onError: (msg: string) => void;
}) {
  const [encounterId, setEncounterId] = useState(target?.encounterId ?? '');
  const [bedId, setBedId] = useState('');
  const [admissionType, setAdmissionType] = useState('emergency');
  const [diagnosis, setDiagnosis] = useState('');
  const [busy, setBusy] = useState(false);

  // Available beds
  const availableBeds = useMemo(() => {
    return wards.flatMap(w =>
      w.rooms.flatMap(r =>
        r.beds.filter(b => b.status === 'available').map(b => ({
          id: b.id,
          code: b.bedCode,
          ward: w.name,
          room: r.name,
        }))
      )
    );
  }, [wards]);

  const handleSubmit = async () => {
    if (!encounterId) { onError('Encounter ID is required.'); return; }
    if (!bedId) { onError('Select an available bed.'); return; }
    if (!diagnosis.trim()) { onError('Admitting diagnosis is required.'); return; }
    setBusy(true);
    try {
      await admissionApi.store(encounterId, {
        bedId,
        admissionType,
        admittingDiagnosis: diagnosis.trim(),
      });
      onAdmitted();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Admission failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Admit Patient" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void handleSubmit()} loading={busy}>Admit Patient</Button>
      </>
    }>
      <div className="form-grid">
        <Input
          label="Encounter ID"
          value={encounterId}
          onChange={e => setEncounterId(e.target.value)}
          placeholder="Encounter to admit"
        />
        <Select label="Admission Type" value={admissionType} onChange={e => setAdmissionType(e.target.value)}>
          <option value="emergency">Emergency</option>
          <option value="planned">Planned</option>
          <option value="transfer_in">Transfer In</option>
        </Select>
        <Select label="Available Bed" value={bedId} onChange={e => setBedId(e.target.value)}>
          <option value="">Select bed ({availableBeds.length} available)...</option>
          {availableBeds.map(b => (
            <option key={b.id} value={b.id}>{b.code} — {b.ward} / {b.room}</option>
          ))}
        </Select>
        <Input
          label="Admitting Diagnosis"
          value={diagnosis}
          onChange={e => setDiagnosis(e.target.value)}
          placeholder="Primary diagnosis"
        />
      </div>
      {availableBeds.length === 0 && (
        <Alert tone="warning">No beds currently available. Free a bed or add capacity.</Alert>
      )}
    </Dialog>
  );
}
