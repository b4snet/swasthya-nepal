import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { pharmacyApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Input } from '../components/ui';
import { ApiError } from '../api/client';
import './pharmacy.css';

export function PharmacyPage() {
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;
  const [prescriptionId, setPrescriptionId] = useState('');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);



  const prescription = useFetch(
    () => (prescriptionId ? pharmacyApi.showPrescription(prescriptionId, fac) : Promise.resolve(null)),
    [prescriptionId, fac],
  );

  const handleVerify = async () => {
    if (!prescriptionId) return;
    setBusy(true);
    try {
      await pharmacyApi.verify(prescriptionId, fac);
      setNotice({ tone: 'success', text: 'Prescription verified.' });
      void prescription.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Verification failed.' });
    } finally {
      setBusy(false);
    }
  };

  const handleDispense = async () => {
    if (!prescriptionId) return;
    setBusy(true);
    try {
      await pharmacyApi.dispense(prescriptionId, {}, fac);
      setNotice({ tone: 'success', text: 'Prescription dispensed.' });
      void prescription.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Dispensing failed.' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Pharmacy</h1>
          <span className="page__sub">Dispensing, returns, and stock management</span>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <Card title="Prescription lookup" className="pharma__lookup">
        <div className="stack">
          <Input
            label="Prescription ID"
            value={prescriptionId}
            onChange={(e) => setPrescriptionId(e.target.value)}
            placeholder="Enter prescription ID"
          />
          {prescription.data && (
            <div className="pharma__detail">
              <div className="pharma__detail-item">
                <span className="pharma__detail-label">Status</span>
                <span className={`pharma__status pharma__status--${prescription.data.status}`}>{prescription.data.status}</span>
              </div>
              <div className="pharma__detail-item">
                <span className="pharma__detail-label">Patient</span>
                <span className="pharma__detail-value">{prescription.data.patientId}</span>
              </div>
              <div className="pharma__detail-item">
                <span className="pharma__detail-label">Items</span>
                <span className="pharma__detail-value">{prescription.data.lines.length}</span>
              </div>
              {prescription.data.verifiedAt && (
                <div className="pharma__detail-item">
                  <span className="pharma__detail-label">Verified</span>
                  <span className="pharma__detail-value">{new Date(prescription.data.verifiedAt).toLocaleString()}</span>
                </div>
              )}
            </div>
          )}
          <div className="pharma__actions">
            {prescription.data?.status === 'draft' && (
              <Button onClick={() => void handleVerify()} loading={busy}>Verify prescription</Button>
            )}
            {prescription.data?.status === 'active' && (
              <Button onClick={() => void handleDispense()} loading={busy}>Dispense all</Button>
            )}
          </div>
        </div>
      </Card>

      {prescription.data && prescription.data.lines.length > 0 && (
        <Card title="Prescription lines" className="pharma__lines">
          <table className="data-table">
            <thead>
              <tr>
                <th>Medication</th>
                <th>Dose</th>
                <th>Route</th>
                <th>Frequency</th>
                <th>Status</th>
                <th>Stock</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {prescription.data.lines.map((line) => (
                <tr key={line.id}>
                  <td data-label="Medication">
                    <span className="pharma__med-name">{line.medication?.genericName ?? '—'}</span>
                    <span className="pharma__med-strength">{line.medication?.strength}</span>
                  </td>
                  <td data-label="Dose">{line.dose}</td>
                  <td data-label="Route">{line.route}</td>
                  <td data-label="Frequency">{line.frequency}</td>
                  <td data-label="Status">
                    <span className={`pharma__status pharma__status--${line.status}`}>{line.status}</span>
                  </td>
                  <td data-label="Stock" className="num">{line.availableQuantity ?? '—'}</td>
                  <td>
                    {line.status === 'active' && line.batchId && (
                      <span className="pharma__batch">Batch: {line.batchNumber}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
