import { useState, useEffect, useCallback } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { billingApi } from '../api/endpoints';
import {
  DollarSign,
  CreditCard,
  Receipt,
  AlertTriangle,
  CheckCircle,
  ArrowRight,
  RefreshCw,
} from 'lucide-react';
import './billing-premium.css';

interface Invoice {
  id: string;
  invoiceNumber: string;
  patientId: string;
  patient?: { fullName: string; mrn: string };
  status: string;
  totalMinor: number;
  totalTaxMinor: number;
  paidMinor: number;
  issuedAt: string;
  lines?: Array<{ id: string; lineNo: number; description: string; amountMinor: number; taxMinor: number }>;
}

const STATUS_CONFIG: Record<string, { color: string; bg: string; label: string }> = {
  draft: { color: '#64748b', bg: '#f8fafc', label: 'Draft' },
  issued: { color: '#2563eb', bg: '#eff6ff', label: 'Issued' },
  partially_paid: { color: '#d97706', bg: '#fffbeb', label: 'Partial' },
  paid: { color: '#059669', bg: '#ecfdf5', label: 'Paid' },
  voided: { color: '#dc2626', bg: '#fef2f2', label: 'Voided' },
};

function formatMoney(minor: number): string {
  return `Rs. ${(minor / 100).toLocaleString('en-NP', { minimumFractionDigits: 0 })}`;
}

export function BillingPage() {
  const { invoiceId } = useParams<{ invoiceId?: string }>();
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;

  if (invoiceId) {
    return <InvoiceDetail invoiceId={invoiceId} fac={fac} />;
  }

  return <InvoiceList fac={fac} />;
}

function InvoiceList({ fac }: { fac: string | null }) {
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');

  const fetchInvoices = useCallback(async () => {
    setLoading(true);
    try {
      // Use the billing API to list invoices
      const res = await fetch(`/api/v1/invoices?facilityId=${fac ?? ''}`, {
        headers: { 'Authorization': `Bearer ${localStorage.getItem('token') ?? ''}` },
      });
      const data = await res.json();
      setInvoices(data?.data?.data ?? data?.data ?? []);
    } catch {
      setInvoices([]);
    } finally {
      setLoading(false);
    }
  }, [fac]);

  useEffect(() => {
    fetchInvoices();
  }, [fetchInvoices]);

  const filtered = filter === 'all' ? invoices : invoices.filter((inv) => inv.status === filter);

  const totalOutstanding = invoices
    .filter((inv) => inv.status !== 'voided' && inv.status !== 'paid')
    .reduce((sum, inv) => sum + (inv.totalMinor - inv.paidMinor), 0);

  const totalPaid = invoices
    .filter((inv) => inv.status === 'paid')
    .reduce((sum, inv) => sum + inv.paidMinor, 0);

  return (
    <div className="bp-page">
      <header className="bp-header">
        <div className="bp-header__title">
          <DollarSign size={24} />
          <div>
            <h1>Billing</h1>
            <p className="bp-header__subtitle">Invoices, payments, and financial management</p>
          </div>
        </div>
        <div className="bp-header__actions">
          <button onClick={fetchInvoices} className="bp-refresh-btn">
            <RefreshCw size={16} />
          </button>
        </div>
      </header>

      {/* KPI Cards */}
      <div className="bp-kpi-row">
        <div className="bp-kpi bp-kpi--total">
          <Receipt size={20} />
          <div className="bp-kpi__content">
            <span className="bp-kpi__value">{invoices.length}</span>
            <span className="bp-kpi__label">Total Invoices</span>
          </div>
        </div>
        <div className="bp-kpi bp-kpi--outstanding">
          <AlertTriangle size={20} />
          <div className="bp-kpi__content">
            <span className="bp-kpi__value">{formatMoney(totalOutstanding)}</span>
            <span className="bp-kpi__label">Outstanding</span>
          </div>
        </div>
        <div className="bp-kpi bp-kpi--paid">
          <CheckCircle size={20} />
          <div className="bp-kpi__content">
            <span className="bp-kpi__value">{formatMoney(totalPaid)}</span>
            <span className="bp-kpi__label">Collected</span>
          </div>
        </div>
      </div>

      {/* Filter pills */}
      <div className="bp-filters">
        {['all', 'issued', 'partially_paid', 'paid', 'voided'].map((f) => (
          <button
            key={f}
            className={`bp-filter-pill ${filter === f ? 'bp-filter-pill--active' : ''}`}
            onClick={() => setFilter(f)}
          >
            {f === 'all' ? 'All' : STATUS_CONFIG[f]?.label ?? f}
          </button>
        ))}
      </div>

      {/* Invoice table */}
      <div className="bp-table-wrap">
        {loading ? (
          <div className="bp-loading">
            <div className="bp-loading__spinner" />
          </div>
        ) : filtered.length === 0 ? (
          <div className="bp-empty">
            <Receipt size={48} />
            <p>No invoices found</p>
          </div>
        ) : (
          <div className="bp-table">
            <div className="bp-table__header">
              <span>Invoice</span>
              <span>Patient</span>
              <span>Total</span>
              <span>Paid</span>
              <span>Outstanding</span>
              <span>Status</span>
              <span>Date</span>
              <span />
            </div>
            {filtered.map((inv) => {
              const outstanding = inv.totalMinor - inv.paidMinor;
              const status = STATUS_CONFIG[inv.status] ?? STATUS_CONFIG.issued;
              return (
                <div key={inv.id} className="bp-table__row">
                  <span className="bp-table__number">{inv.invoiceNumber}</span>
                  <span className="bp-table__patient">
                    {inv.patient?.fullName ?? '—'}
                    <small>{inv.patient?.mrn}</small>
                  </span>
                  <span className="bp-table__amount">{formatMoney(inv.totalMinor)}</span>
                  <span className="bp-table__amount bp-table__amount--paid">{formatMoney(inv.paidMinor)}</span>
                  <span className={`bp-table__amount ${outstanding > 0 ? 'bp-table__amount--outstanding' : ''}`}>
                    {formatMoney(outstanding)}
                  </span>
                  <span className="bp-status" style={{ color: status.color, background: status.bg }}>
                    {status.label}
                  </span>
                  <span className="bp-table__date">
                    {inv.issuedAt ? new Date(inv.issuedAt).toLocaleDateString() : '—'}
                  </span>
                  <Link to={`/billing/${inv.id}`} className="bp-table__action">
                    <ArrowRight size={16} />
                  </Link>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}

function InvoiceDetail({ invoiceId, fac }: { invoiceId: string; fac: string | null }) {
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [loading, setLoading] = useState(true);
  const [method, setMethod] = useState('cash');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const fetchInvoice = useCallback(async () => {
    setLoading(true);
    try {
      const res = await billingApi.invoiceShow(invoiceId, fac);
      setInvoice(res as unknown as Invoice);
    } catch {
      setNotice({ type: 'error', text: 'Failed to load invoice' });
    } finally {
      setLoading(false);
    }
  }, [invoiceId, fac]);

  useEffect(() => {
    fetchInvoice();
  }, [fetchInvoice]);

  const handlePay = async () => {
    if (!invoice) return;
    setBusy(true);
    try {
      const idempotencyKey = `web-${invoiceId}-${Date.now()}`;
      await billingApi.pay(invoiceId, {
        method,
        amountMinor: invoice.totalMinor - invoice.paidMinor,
        idempotencyKey,
      }, fac);
      setNotice({ type: 'success', text: 'Payment captured successfully' });
      await fetchInvoice();
    } catch {
      setNotice({ type: 'error', text: 'Payment failed' });
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="bp-page">
        <div className="bp-loading"><div className="bp-loading__spinner" /></div>
      </div>
    );
  }

  if (!invoice) {
    return (
      <div className="bp-page">
        <div className="bp-empty">
          <AlertTriangle size={48} />
          <p>Invoice not found</p>
          <Link to="/billing" className="bp-link">← Back to Billing</Link>
        </div>
      </div>
    );
  }

  const outstanding = invoice.totalMinor - invoice.paidMinor;
  const status = STATUS_CONFIG[invoice.status] ?? STATUS_CONFIG.issued;

  return (
    <div className="bp-page">
      <header className="bp-header">
        <div className="bp-header__title">
          <Link to="/billing" className="bp-back-link">← Billing</Link>
          <div>
            <h1>Invoice {invoice.invoiceNumber}</h1>
            <p className="bp-header__subtitle">
              <span className="bp-status" style={{ color: status.color, background: status.bg }}>{status.label}</span>
              {' · '}
              {invoice.issuedAt ? new Date(invoice.issuedAt).toLocaleString() : '—'}
            </p>
          </div>
        </div>
      </header>

      {notice && (
        <div className={`bp-alert bp-alert--${notice.type}`}>
          {notice.type === 'success' ? <CheckCircle size={16} /> : <AlertTriangle size={16} />}
          {notice.text}
        </div>
      )}

      {/* Invoice totals */}
      <div className="bp-detail-grid">
        <div className="bp-detail-card">
          <h3>Invoice Summary</h3>
          <div className="bp-detail-rows">
            <div className="bp-detail-row">
              <span>Total</span>
              <span className="bp-detail-value">{formatMoney(invoice.totalMinor)}</span>
            </div>
            <div className="bp-detail-row">
              <span>Tax</span>
              <span className="bp-detail-value">{formatMoney(invoice.totalTaxMinor)}</span>
            </div>
            <div className="bp-detail-row">
              <span>Paid</span>
              <span className="bp-detail-value bp-detail-value--paid">{formatMoney(invoice.paidMinor)}</span>
            </div>
            <div className="bp-detail-row bp-detail-row--total">
              <span>Outstanding</span>
              <span className="bp-detail-value">{formatMoney(outstanding)}</span>
            </div>
          </div>
        </div>

        {outstanding > 0 && (
          <div className="bp-detail-card bp-detail-card--payment">
            <h3>Capture Payment</h3>
            <div className="bp-payment-form">
              <div className="bp-payment-methods">
                {['cash', 'card', 'bank_transfer', 'digital_wallet'].map((m) => (
                  <button
                    key={m}
                    className={`bp-method-btn ${method === m ? 'bp-method-btn--active' : ''}`}
                    onClick={() => setMethod(m)}
                  >
                    <CreditCard size={16} />
                    {m === 'bank_transfer' ? 'Bank' : m === 'digital_wallet' ? 'Wallet' : m.charAt(0).toUpperCase() + m.slice(1)}
                  </button>
                ))}
              </div>
              <button
                className="bp-pay-btn"
                onClick={handlePay}
                disabled={busy || outstanding <= 0}
              >
                {busy ? 'Processing...' : `Pay ${formatMoney(outstanding)}`}
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Line items */}
      {invoice.lines && invoice.lines.length > 0 && (
        <div className="bp-detail-card bp-detail-card--full">
          <h3>Line Items</h3>
          <div className="bp-line-table">
            <div className="bp-line-header">
              <span>#</span>
              <span>Description</span>
              <span>Amount</span>
              <span>Tax</span>
            </div>
            {invoice.lines.map((line) => (
              <div key={line.id} className="bp-line-row">
                <span className="bp-line-no">{line.lineNo}</span>
                <span>{line.description}</span>
                <span className="bp-line-amount">{formatMoney(line.amountMinor)}</span>
                <span className="bp-line-amount">{formatMoney(line.taxMinor)}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
