import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { billingApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, ErrorState, FinancialStatus, Select, Spinner, money } from '../components/ui';
import { ApiError } from '../api/client';

export function BillingPage() {
  const { invoiceId } = useParams<{ invoiceId?: string }>();
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;
  const canPay = hasRole('hospital_admin', 'accountant', 'billing_clerk');

  if (invoiceId) {
    return <InvoicePanel invoiceId={invoiceId} fac={fac} canPay={canPay} />;
  }

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Billing</h1>
          <span className="page__sub">Invoices and payments are captured against the real API</span>
        </div>
      </div>
      <Card>
        <p className="muted">
          Open an invoice from a completed encounter to see charges and capture payment. To issue an
          invoice, finish an encounter in the <a href="/encounters">doctor workspace</a> and use the
          invoice action there.
        </p>
        <p className="muted small mt-4">
          Outstanding-balance aggregation is a Phase 13 (Finance) item — the frontend does not invent it.
        </p>
      </Card>
    </div>
  );
}

function InvoicePanel({ invoiceId, fac, canPay }: { invoiceId: string; fac: string | null; canPay: boolean }) {
  const invoice = useFetch(() => billingApi.invoiceShow(invoiceId, fac), [invoiceId, fac]);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [method, setMethod] = useState('cash');
  const [busy, setBusy] = useState(false);

  if (invoice.loading) return <Spinner />;
  if (invoice.error) return <ErrorState error={invoice.error} onRetry={() => void invoice.refresh()} />;
  const inv = invoice.data!;
  const outstanding = inv.totalMinor - inv.paidMinor;
  const paid = inv.status === 'paid';

  const pay = async () => {
    setBusy(true);
    setNotice(null);
    const idempotencyKey = `web-${invoiceId}-${Date.now()}`;
    try {
      const res = await billingApi.pay(invoiceId, { method, amountMinor: outstanding, idempotencyKey }, fac);
      setNotice({ tone: 'success', text: `Payment captured (${res.method}) — ${money(res.amountMinor)}.` });
      await invoice.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Payment failed.' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="page page--narrow">
      <div className="page__head">
        <div className="page__title">
          <h1>Invoice <span className="mono">{inv.invoiceNumber}</span></h1>
          <span className="page__sub">
            <FinancialStatus status={inv.status} /> · issued {inv.issuedAt ? new Date(inv.issuedAt).toLocaleString() : '—'}
          </span>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <Card>
        <table className="data-table">
          <thead>
            <tr>
              <th>Line</th>
              <th>Description</th>
              <th className="num">Amount</th>
            </tr>
          </thead>
          <tbody>
            {(inv.lines ?? []).map((l) => (
              <tr key={l.id}>
                <td data-label="Line" className="num">{l.lineNo}</td>
                <td data-label="Description">{l.description}</td>
                <td data-label="Amount" className="num">{money(l.amountMinor)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <dl className="kv mt-4">
          <div><dt>Total</dt><dd className="num">{money(inv.totalMinor)}</dd></div>
          <div><dt>Paid</dt><dd className="num">{money(inv.paidMinor)}</dd></div>
          <div><dt>Outstanding</dt><dd className="num">{money(outstanding)}</dd></div>
        </dl>
      </Card>

      {!paid && canPay && (
        <Card title="Capture payment">
          <div className="stack">
            <Select label="Method" value={method} onChange={(e) => setMethod(e.target.value)}>
              <option value="cash">Cash</option>
              <option value="card">Card</option>
              <option value="wallet">Wallet</option>
              <option value="bank">Bank transfer</option>
            </Select>
            <p className="muted small">
              Captures {money(outstanding)}. Retries are safe: the backend deduplicates by idempotency key.
            </p>
            <div className="row">
              <Button onClick={() => void pay()} loading={busy}>
                Capture payment
              </Button>
            </div>
          </div>
        </Card>
      )}
    </div>
  );
}
