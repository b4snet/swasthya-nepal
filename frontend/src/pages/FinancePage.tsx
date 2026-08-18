import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { financeApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Dialog, EmptyState, Input, Spinner, money } from '../components/ui';
import { ApiError } from '../api/client';

export function FinancePage() {
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;

  const settlements = useFetch(() => financeApi.settlements(fac), [fac]);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const [reconcileOpen, setReconcileOpen] = useState(false);
  const [settlementDate, setSettlementDate] = useState(new Date().toISOString().slice(0, 10));
  const [actualMinor, setActualMinor] = useState('');
  const [notes, setNotes] = useState('');

  const handleReconcile = async () => {
    if (!settlementDate || !actualMinor) return;
    setBusy(true);
    try {
      await financeApi.reconcileSettlement({
        settlementDate,
        actualMinor: parseInt(actualMinor, 10),
        notes: notes || undefined,
      }, fac);
      setNotice({ tone: 'success', text: 'Settlement reconciled.' });
      setReconcileOpen(false);
      setActualMinor('');
      setNotes('');
      void settlements.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Reconciliation failed.' });
    } finally {
      setBusy(false);
    }
  };

  const data = settlements.data ?? [];

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Finance</h1>
          <span className="page__sub">Settlements, aging, and reconciliation</span>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <Card title="Cashier settlements">
        <div className="row mb-4">
          <Button onClick={() => setReconcileOpen(true)}>Reconcile today</Button>
        </div>
        {settlements.loading ? <Spinner /> : data.length === 0 ? (
          <EmptyState title="No settlements" body="Daily settlements appear here after reconciliation." />
        ) : (
          <table className="data-table">
            <thead><tr><th>Date</th><th>Cashier</th><th className="num">Expected</th><th className="num">Actual</th><th className="num">Variance</th><th>Status</th></tr></thead>
            <tbody>
              {data.map((s) => (
                <tr key={s.id}>
                  <td>{s.settlementDate}</td>
                  <td>{s.cashierId}</td>
                  <td className="num">{money(s.expectedMinor)}</td>
                  <td className="num">{money(s.actualMinor)}</td>
                  <td className={`num ${s.varianceMinor !== 0 ? 'text-danger' : ''}`}>{money(s.varianceMinor)}</td>
                  <td><span className={`status-chip status-chip--${s.status === 'reconciled' ? 'success' : 'info'}`}>{s.status}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      <Dialog
        open={reconcileOpen}
        onClose={() => setReconcileOpen(false)}
        title="Reconcile settlement"
        footer={
          <>
            <Button variant="ghost" onClick={() => setReconcileOpen(false)}>Cancel</Button>
            <Button onClick={() => void handleReconcile()} loading={busy} disabled={!actualMinor}>Reconcile</Button>
          </>
        }
      >
        <div className="stack">
          <Input label="Settlement date" type="date" value={settlementDate} onChange={(e) => setSettlementDate(e.target.value)} />
          <Input label="Actual amount (minor units)" type="number" value={actualMinor} onChange={(e) => setActualMinor(e.target.value)} placeholder="e.g. 150000" />
          <Input label="Notes" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional reconciliation notes" />
        </div>
      </Dialog>
    </div>
  );
}
