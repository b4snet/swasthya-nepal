import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { pharmacyApi, inventoryApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, SkeletonTable, StatusChip, Tabs } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import type { PharmacyPrescription, PharmacyPrescriptionLine } from '../api/types';
import './pharmacy.css';

interface StockAlert {
  id: string;
  medicationId: string;
  quantityOnHand: number;
  reorderLevel: number;
  medication: { genericName: string; strength: string; form: string } | null | undefined;
}

const STATUS_CONFIG: Record<string, { label: string; tone: 'success' | 'info' | 'warning' | 'danger' | 'neutral' }> = {
  drafted:   { label: 'New',          tone: 'neutral' },
  active:    { label: 'Verified',     tone: 'info' },
  dispensed: { label: 'Dispensed',    tone: 'success' },
  discontinued: { label: 'Discontinued', tone: 'warning' },
  expired:   { label: 'Expired',      tone: 'danger' },
};

const LINE_STATUS: Record<string, { label: string; tone: 'success' | 'info' | 'warning' | 'danger' | 'neutral' }> = {
  ordered:   { label: 'Pending',      tone: 'warning' },
  dispensed: { label: 'Dispensed',    tone: 'success' },
  cancelled: { label: 'Cancelled',    tone: 'neutral' },
  reversed:  { label: 'Reversed',     tone: 'danger' },
};

export function PharmacyPage() {
  const { selectedFacilityId: fac, organizationId } = useTenant();
  const [tab, setTab] = useState('all');
  const [search, setSearch] = useState('');
  const [selectedRx, setSelectedRx] = useState<PharmacyPrescription | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [, setBusy] = useState(false);

  const prescriptions = useFetch(
    () => pharmacyApi.list({ search: search || undefined, facilityId: fac }),
    [fac, search],
  );

  const reorderAlerts = useFetch(
    () => organizationId ? inventoryApi.reorderAlerts(organizationId, fac) : Promise.resolve([]),
    [organizationId, fac],
  );

  const refreshAll = useCallback(() => {
    void prescriptions.refresh();
    void reorderAlerts.refresh();
  }, [prescriptions, reorderAlerts]);

  useEffect(() => { const id = setInterval(refreshAll, 30000); return () => clearInterval(id); }, [refreshAll]);

  const allRx = (prescriptions.data?.data ?? []) as PharmacyPrescription[];

  const census = useMemo(() => {
    const pending = allRx.filter(r => r.status === 'drafted').length;
    const verified = allRx.filter(r => r.status === 'active').length;
    const dispensed = allRx.filter(r => r.status === 'dispensed').length;
    const totalLines = allRx.reduce((s, r) => s + (r.lineCount ?? r.lines?.length ?? 0), 0);
    const alerts = (reorderAlerts.data ?? []).length;
    return { pending, verified, dispensed, totalLines, alerts };
  }, [allRx, reorderAlerts.data]);

  const filtered = useMemo(() => {
    switch (tab) {
      case 'pending':  return allRx.filter(r => r.status === 'drafted');
      case 'verified': return allRx.filter(r => r.status === 'active');
      case 'dispensed': return allRx.filter(r => r.status === 'dispensed');
      default: return allRx;
    }
  }, [allRx, tab]);

  const tabs = [
    { id: 'all',      label: 'All (' + allRx.length + ')' },
    { id: 'pending',  label: 'Needs Verification (' + census.pending + ')' },
    { id: 'verified', label: 'Ready to Dispense (' + census.verified + ')' },
    { id: 'dispensed', label: 'Dispensed (' + census.dispensed + ')' },
    { id: 'stock',    label: 'Stock Alerts (' + census.alerts + ')' },
  ];

  if (prescriptions.loading) return <SkeletonTable rows={6} cols={5} />;

  return (
    <div className="page pharmacy-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Pharmacy</h1>
          <p className="page__subtitle">Prescription verification, dispensing, and inventory management</p>
        </div>
        <div className="pharmacy-actions">
          <Button variant="ghost" onClick={() => void refreshAll()}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      <div className="pharmacy-census">
        <div className="pharmacy-census-card pharmacy-census-card--pending">
          <span className="pharmacy-census-value">{census.pending}</span>
          <span className="pharmacy-census-label">Needs Verification</span>
        </div>
        <div className="pharmacy-census-card pharmacy-census-card--verified">
          <span className="pharmacy-census-value">{census.verified}</span>
          <span className="pharmacy-census-label">Ready to Dispense</span>
        </div>
        <div className="pharmacy-census-card pharmacy-census-card--dispensed">
          <span className="pharmacy-census-value">{census.dispensed}</span>
          <span className="pharmacy-census-label">Dispensed</span>
        </div>
        <div className="pharmacy-census-card pharmacy-census-card--lines">
          <span className="pharmacy-census-value">{census.totalLines}</span>
          <span className="pharmacy-census-label">Total Lines</span>
        </div>
        <div className="pharmacy-census-card pharmacy-census-card--alerts">
          <span className="pharmacy-census-value" style={{ color: census.alerts > 0 ? "#ef4444" : undefined }}>
            {census.alerts}
          </span>
          <span className="pharmacy-census-label">Stock Alerts</span>
        </div>
      </div>

      <div className="pharmacy-filters">
        <Input label="Search" value={search} onChange={e => setSearch(e.target.value)} placeholder="Search patient, MRN, or prescriber..." style={{ maxWidth: 320 }} />
      </div>

      <Tabs tabs={tabs} active={tab} onChange={t => setTab(t)} />

      {tab === 'stock' ? (
        <Card className="pharmacy-section-card">
          {(reorderAlerts.data ?? []).length === 0 ? (
            <EmptyState title="No stock alerts" body="All medications are above reorder levels." />
          ) : (
            <table className="data-table">
              <thead><tr><th>Medication</th><th>Strength</th><th>On Hand</th><th>Reorder Level</th><th>Status</th></tr></thead>
              <tbody>
                {(reorderAlerts.data as StockAlert[]).map(item => (
                  <tr key={item.id}>
                    <td>{item.medication?.genericName ?? item.medicationId}</td>
                    <td>{item.medication?.strength ?? '-'}</td>
                    <td className="num" style={{ color: item.quantityOnHand <= 0 ? "#ef4444" : "#f59e0b" }}>{item.quantityOnHand}</td>
                    <td className="num">{item.reorderLevel}</td>
                    <td><StatusChip tone={item.quantityOnHand <= 0 ? 'danger' : 'warning'} label={item.quantityOnHand <= 0 ? 'Out of Stock' : 'Low Stock'} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      ) : (
        <Card className="pharmacy-section-card">
          {filtered.length === 0 ? (
            <EmptyState title="No prescriptions" body={tab === 'all' ? 'No prescriptions in the system.' : 'No ' + tab + ' prescriptions.'} />
          ) : (
            <table className="data-table pharmacy-table">
              <thead><tr><th>Patient</th><th>Prescriber</th><th>Lines</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
              <tbody>
                {filtered.map(rx => {
                  const cfg = STATUS_CONFIG[rx.status] ?? STATUS_CONFIG.drafted;
                  return (
                    <tr key={rx.id} className={'pharmacy-row pharmacy-row--' + rx.status}>
                      <td>
                        <Link to={'/patients/' + rx.patientId} className="pharmacy-patient-link">{rx.patientName ?? rx.patientId.slice(0, 8) + '...'}</Link>
                        {rx.patientMrn && <span className="pharmacy-mrn">{rx.patientMrn}</span>}
                      </td>
                      <td>{rx.prescriberName ?? '-'}</td>
                      <td className="num">{rx.lineCount ?? rx.lines?.length ?? 0}</td>
                      <td><StatusChip tone={cfg.tone} label={cfg.label} /></td>
                      <td className="pharmacy-time">{rx.createdAt ? new Date(rx.createdAt).toLocaleDateString() : '-'}</td>
                      <td>
                        <div className="pharmacy-row-actions">
                          <Button variant="ghost" size="sm" onClick={() => void loadDetail(rx.id)}>Open</Button>
                          {rx.status === 'drafted' && <Button variant="primary" size="sm" onClick={() => void handleVerify(rx.id)}>Verify</Button>}
                          {rx.status === 'active' && <Button variant="primary" size="sm" onClick={() => void handleDispense(rx.id)}>Dispense</Button>}
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

      {selectedRx && <PrescriptionDetailDialog prescription={selectedRx} onClose={() => setSelectedRx(null)} onAction={() => { setSelectedRx(null); void refreshAll(); }} onError={setError} />}
    </div>
  );

  async function loadDetail(id: string) {
    setBusy(true); setError(null);
    try { const rx = await pharmacyApi.showPrescription(id, fac); setSelectedRx(rx as PharmacyPrescription); }
    catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed to load prescription.'); }
    finally { setBusy(false); }
  }
  async function handleVerify(id: string) {
    setBusy(true); setError(null);
    try { await pharmacyApi.verify(id, fac); void refreshAll(); }
    catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Verification failed.'); }
    finally { setBusy(false); }
  }
  async function handleDispense(id: string) {
    setBusy(true); setError(null);
    try { await pharmacyApi.dispense(id, {}, fac); void refreshAll(); }
    catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Dispensing failed.'); }
    finally { setBusy(false); }
  }
}

function PrescriptionDetailDialog({ prescription: rx, onClose, onAction, onError }: {
  prescription: PharmacyPrescription; onClose: () => void; onAction: () => void; onError: (msg: string) => void;
}) {
  const { selectedFacilityId: fac } = useTenant();
  const [busy, setBusy] = useState(false);
  const handleVerify = async () => { setBusy(true); try { await pharmacyApi.verify(rx.id, fac); onAction(); } catch (e: unknown) { onError(e instanceof ApiError ? e.message : 'Verification failed.'); } finally { setBusy(false); } };
  const handleDispense = async () => { setBusy(true); try { await pharmacyApi.dispense(rx.id, {}, fac); onAction(); } catch (e: unknown) { onError(e instanceof ApiError ? e.message : 'Dispensing failed.'); } finally { setBusy(false); } };

  return (
    <Dialog open onClose={onClose} title={'Prescription - ' + (rx.patientName ?? rx.patientId.slice(0, 8))} footer={<>
      <Button variant="ghost" onClick={onClose}>Close</Button>
      {rx.status === 'drafted' && <Button onClick={() => void handleVerify()} loading={busy}>Verify</Button>}
      {rx.status === 'active' && <Button onClick={() => void handleDispense()} loading={busy}>Dispense All</Button>}
    </>}>
      <div className="pharma-detail-header">
        <div className="pharma-detail-item"><span className="pharma-detail-label">Patient</span><span>{rx.patientName ?? rx.patientId}</span></div>
        <div className="pharma-detail-item"><span className="pharma-detail-label">Status</span><StatusChip tone={STATUS_CONFIG[rx.status]?.tone ?? "neutral"} label={STATUS_CONFIG[rx.status]?.label ?? rx.status} /></div>
        <div className="pharma-detail-item"><span className="pharma-detail-label">Verified</span><span>{rx.verifiedAt ? new Date(rx.verifiedAt).toLocaleString() : "-"}</span></div>
      </div>
      {rx.lines.length > 0 && (
        <table className="data-table pharma-lines-table">
          <thead><tr><th>Medication</th><th>Dose</th><th>Route</th><th>Freq</th><th>Qty</th><th>Stock</th><th>Status</th></tr></thead>
          <tbody>
            {rx.lines.map((line: PharmacyPrescriptionLine) => {
              const lCfg = LINE_STATUS[line.status] ?? LINE_STATUS.ordered;
              return (
                <tr key={line.id}>
                  <td><span className="pharma-med-name">{line.medication?.genericName ?? '-'}</span><span className="pharma-med-strength">{line.medication?.strength}</span></td>
                  <td>{line.dose}</td><td>{line.route}</td><td>{line.frequency}</td>
                  <td className="num">{line.quantityMinor ?? '-'}</td>
                  <td className="num" style={{ color: (line.availableQuantity ?? 0) <= 0 ? '#ef4444' : undefined }}>{line.availableQuantity ?? '-'}</td>
                  <td>
                    <StatusChip tone={lCfg.tone} label={lCfg.label} />
                    {line.batchNumber && <span className="pharma-batch-tag">Batch: {line.batchNumber}</span>}
                    {line.dualVerifiedByStaffId === null && line.status === 'dispensed' && line.medication?.isControlled && <span className="pharma-dual-tag">Dual verify needed</span>}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </Dialog>
  );
}
