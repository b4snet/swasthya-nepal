import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../../context/TenantContext';
import { useFetch } from '../../hooks/useFetch';
import { pharmacyApi, inventoryApi } from '../../api/endpoints';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../../components/ui';
import '../modules/pharmacy-cmd.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface Prescription {
  id: string;
  patientId: string;
  encounterId: string | null;
  prescriberStaffId: string;
  status: string;
  notes: string | null;
  createdAt: string;
  lines: PrescriptionLine[];
}

interface PrescriptionLine {
  id: string;
  prescriptionId: string;
  medicationId: string;
  dosage: string;
  frequency: string;
  route: string;
  quantityPrescribed: number;
  quantityDispensed: number;
  unitPriceMinor: number;
  lineTotalMinor: number;
  status: string;
  medication?: { id: string; genericName: string; brandName: string | null; strength: string; form: string };
}

/* ── Constants ───────────────────────────────────────────────────── */

const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  drafted: { label: 'Drafted', color: '#6b7280', bg: '#f3f4f6' },
  active: { label: 'Active', color: '#f59e0b', bg: '#fef3c7' },
  verified: { label: 'Verified', color: '#3b82f6', bg: '#dbeafe' },
  dispensed: { label: 'Dispensed', color: '#10b981', bg: '#ecfdf5' },
  discontinued: { label: 'Discontinued', color: '#ef4444', bg: '#fee2e2' },
  expired: { label: 'Expired', color: '#6b7280', bg: '#f3f4f6' },
  returned: { label: 'Returned', color: '#8b5cf6', bg: '#f5f3ff' },
};

/* ── Main Component ──────────────────────────────────────────────── */

export function PharmacyDashboard() {
  const { organizationId, selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'queue' | 'verification' | 'dispensing' | 'returns' | 'formulary'>('queue');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Data
  const inventory = useFetch(
    () => organizationId ? inventoryApi.list(organizationId, fac) : Promise.resolve([]),
    [organizationId, fac],
  );



  // Prescription form
  const [prescId, setPrescId] = useState('');
  const [prescDetail, setPrescDetail] = useState<Prescription | null>(null);

  // Return form
  const [retLineId, setRetLineId] = useState('');
  const [retQty, setRetQty] = useState('');
  const [retReason, setRetReason] = useState('');

  const go = async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof Error ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  };

  const loadPrescription = useCallback(async (id: string) => {
    if (!id.trim()) { setPrescDetail(null); return; }
    const data = await go(() => pharmacyApi.showPrescription(id.trim(), fac));
    if (data) setPrescDetail(data as unknown as Prescription);
  }, [fac]);

  const handleVerify = useCallback(async (id: string) => {
    await go(() => pharmacyApi.verify(id, fac));
    void loadPrescription(id);
  }, [fac, loadPrescription]);

  const handleDispense = useCallback(async (id: string) => {
    await go(() => pharmacyApi.dispense(id, {}, fac));
    void loadPrescription(id);
  }, [fac, loadPrescription]);

  const handleReturn = useCallback(async () => {
    if (!retLineId.trim() || !retReason.trim()) return;
    await go(() => pharmacyApi.returnLine(retLineId.trim(), { reason: retReason.trim(), quantityMinor: retQty ? parseInt(retQty) : undefined }, fac));
    setDlg(null); setRetLineId(''); setRetQty(''); setRetReason('');
  }, [retLineId, retQty, retReason, fac]);

  // Census
  const allInventory = (inventory.data ?? []) as unknown as Array<{ id: string; quantityOnHand: number; reorderLevel: number }>;
  const lowStock = useMemo(() => allInventory.filter(i => i.quantityOnHand <= i.reorderLevel), [allInventory]);

  return (
    <div className="page pharma-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Pharmacy</h1>
          <p className="page__subtitle">Prescriptions, verification, dispensing, and medication safety</p>
        </div>
        <div className="pharma-actions">
          <Button variant="ghost" onClick={() => { setPrescId(''); setPrescDetail(null); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="pharma-census">
        <div className="pharma-census-card pharma-census-card--inventory">
          <span className="pharma-census-value">{allInventory.length}</span>
          <span className="pharma-census-label">Inventory Items</span>
        </div>
        <div className="pharma-census-card pharma-census-card--low">
          <span className="pharma-census-value" style={{ color: lowStock.length > 0 ? '#f59e0b' : undefined }}>
            {lowStock.length}
          </span>
          <span className="pharma-census-label">Low Stock</span>
        </div>
        <div className="pharma-census-card pharma-census-card--prescriptions">
          <span className="pharma-census-value">—</span>
          <span className="pharma-census-label">Prescriptions Today</span>
        </div>
        <div className="pharma-census-card pharma-census-card--verified">
          <span className="pharma-census-value">—</span>
          <span className="pharma-census-label">Verified Today</span>
        </div>
        <div className="pharma-census-card pharma-census-card--dispensed">
          <span className="pharma-census-value">—</span>
          <span className="pharma-census-label">Dispensed Today</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="pharma-tabs">
        <button className={`pharma-tab ${activeTab === 'queue' ? 'pharma-tab--active' : ''}`} onClick={() => setActiveTab('queue')}>
          Prescription Queue
        </button>
        <button className={`pharma-tab ${activeTab === 'verification' ? 'pharma-tab--active' : ''}`} onClick={() => setActiveTab('verification')}>
          Verification
        </button>
        <button className={`pharma-tab ${activeTab === 'dispensing' ? 'pharma-tab--active' : ''}`} onClick={() => setActiveTab('dispensing')}>
          Dispensing
        </button>
        <button className={`pharma-tab ${activeTab === 'returns' ? 'pharma-tab--active' : ''}`} onClick={() => setActiveTab('returns')}>
          Returns
        </button>
        <button className={`pharma-tab ${activeTab === 'formulary' ? 'pharma-tab--active' : ''}`} onClick={() => setActiveTab('formulary')}>
          Formulary
        </button>
      </div>

      {/* ── Prescription Queue Tab ────────────────────────── */}
      {activeTab === 'queue' && (
        <Card className="pharma-section-card">
          <div className="pharma-section-header">
            <h3>Prescription Lookup</h3>
          </div>
          <div className="pharma-lookup">
            <Input
              label="Prescription ID"
              value={prescId}
              onChange={e => setPrescId(e.target.value)}
              placeholder="Enter prescription ID"
            />
            <Button variant="primary" onClick={() => void loadPrescription(prescId)} loading={busy}>
              Look Up
            </Button>
          </div>

          {prescDetail && (
            <div className="pharma-prescription-detail">
              <div className="pharma-prescription-header">
                <div>
                  <span className="pharma-prescription-id">{prescDetail.id.slice(0, 12)}...</span>
                  <span className="pharma-prescription-patient">{prescDetail.patientId.slice(0, 8)}...</span>
                </div>
                <span className="pharma-status-badge" style={{
                  color: (STATUS_CONFIG[prescDetail.status] ?? STATUS_CONFIG.drafted).color,
                  backgroundColor: (STATUS_CONFIG[prescDetail.status] ?? STATUS_CONFIG.drafted).bg,
                }}>
                  {(STATUS_CONFIG[prescDetail.status] ?? STATUS_CONFIG.drafted).label}
                </span>
              </div>

              {/* Prescription Lines */}
              <div className="pharma-lines">
                {prescDetail.lines?.map(line => {
                  const med = line.medication;
                  const lineStatus = STATUS_CONFIG[line.status] ?? STATUS_CONFIG.drafted;
                  return (
                    <div key={line.id} className="pharma-line">
                      <div className="pharma-line-info">
                        <span className="pharma-line-med">
                          {med?.genericName ?? 'Unknown'} {med?.strength ?? ''} {med?.form ?? ''}
                        </span>
                        <span className="pharma-line-rx">
                          {line.dosage} · {line.frequency} · {line.route} · Qty: {line.quantityPrescribed}
                        </span>
                      </div>
                      <span className="pharma-line-status" style={{ color: lineStatus.color, backgroundColor: lineStatus.bg }}>
                        {lineStatus.label}
                      </span>
                    </div>
                  );
                })}
              </div>

              {/* Actions */}
              <div className="pharma-actions-row">
                {prescDetail.status === 'active' && (
                  <Button variant="primary" size="sm" onClick={() => void handleVerify(prescDetail.id)}>
                    Verify Prescription
                  </Button>
                )}
                {prescDetail.status === 'verified' && (
                  <Button variant="primary" size="sm" onClick={() => void handleDispense(prescDetail.id)}>
                    Dispense
                  </Button>
                )}
                <Link to={`/patients/${prescDetail.patientId}`}>
                  <Button variant="ghost" size="sm">Patient Record</Button>
                </Link>
              </div>
            </div>
          )}

          {!prescDetail && prescId && !busy && (
            <EmptyState title="No prescription found" body="Enter a valid prescription ID to look up." />
          )}
          {!prescId && !prescDetail && (
            <EmptyState title="Prescription queue" body="Enter a prescription ID to begin verification and dispensing." />
          )}
        </Card>
      )}

      {/* ── Verification Tab ──────────────────────────────── */}
      {activeTab === 'verification' && (
        <Card className="pharma-section-card">
          <div className="pharma-section-header">
            <h3>Pharmacy Verification Queue</h3>
          </div>
          <EmptyState title="Verification queue" body="Active prescriptions requiring pharmacist verification appear here." />
        </Card>
      )}

      {/* ── Dispensing Tab ────────────────────────────────── */}
      {activeTab === 'dispensing' && (
        <Card className="pharma-section-card">
          <div className="pharma-section-header">
            <h3>Dispensing Queue</h3>
          </div>
          <EmptyState title="Dispensing queue" body="Verified prescriptions ready for dispensing appear here." />
        </Card>
      )}

      {/* ── Returns Tab ───────────────────────────────────── */}
      {activeTab === 'returns' && (
        <Card className="pharma-section-card">
          <div className="pharma-section-header">
            <h3>Prescription Returns & Reversals</h3>
            <Button variant="ghost" size="sm" onClick={() => { setRetLineId(''); setRetQty(''); setRetReason(''); setDlg('return'); }}>
              Process Return
            </Button>
          </div>
          <EmptyState title="No returns" body="Process medication returns from the Dispensing queue." />
        </Card>
      )}

      {/* ── Formulary Tab ─────────────────────────────────── */}
      {activeTab === 'formulary' && (
        <Card className="pharma-section-card">
          <div className="pharma-section-header">
            <h3>Hospital Formulary</h3>
          </div>
          <EmptyState title="Medication formulary" body="Configure the hospital formulary in administration." />
        </Card>
      )}

      {/* ── Return Dialog ─────────────────────────────────── */}
      {dlg === 'return' && (
        <Dialog open onClose={() => setDlg(null)} title="Process Medication Return" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={() => void handleReturn()} loading={busy}>Process Return</Button>
          </>
        }>
          <Input label="Prescription Line ID" value={retLineId} onChange={e => setRetLineId(e.target.value)} placeholder="Line to return" />
          <Input label="Quantity" type="number" value={retQty} onChange={e => setRetQty(e.target.value)} placeholder="Optional — defaults to full return" />
          <Input label="Reason" value={retReason} onChange={e => setRetReason(e.target.value)} placeholder="Reason for return" />
          <Alert tone="warning">Returns will restore inventory and trigger billing reversal where applicable.</Alert>
        </Dialog>
      )}
    </div>
  );
}
