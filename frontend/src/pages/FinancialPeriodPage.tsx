import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { enterpriseApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Dialog, EmptyState, Input, Spinner, money } from '../components/ui';
import { ApiError } from '../api/client';

/* ------------------------------------------------------------------ */
/*  Financial Periods (Phase 17 — Enterprise Procurement & Finance)   */
/* ------------------------------------------------------------------ */

interface FinancialPeriod {
  id: string;
  name: string;
  fiscalYear: number;
  periodNumber: number;
  periodType: string;
  startDate?: string;
  endDate?: string;
  status: string;
  totalBudgetMinor: number;
  totalExpensesMinor: number;
  totalRevenueMinor: number;
  closedAt?: string;
}

export function FinancialPeriodPage() {
  const { selectedFacilityId, organizationId } = useTenant();
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  // Form state
  const [formName, setFormName] = useState('');
  const [formYear, setFormYear] = useState(new Date().getFullYear().toString());
  const [formNumber, setFormNumber] = useState('1');
  const [formType, setFormType] = useState('monthly');
  const [formStartDate, setFormStartDate] = useState('');
  const [formEndDate, setFormEndDate] = useState('');

  const periods = useFetch(
    () => organizationId ? enterpriseApi.financialPeriods(organizationId, {}, selectedFacilityId) : Promise.resolve({ data: [] }),
    [organizationId, selectedFacilityId],
  );

  const data: FinancialPeriod[] = (periods.data as any)?.data ?? [];

  const handleCreate = async () => {
    if (!organizationId || !formName || !formYear || !formStartDate || !formEndDate) return;
    setBusy(true);
    try {
      await enterpriseApi.storeFinancialPeriod(organizationId, {
        name: formName,
        fiscalYear: parseInt(formYear, 10),
        periodNumber: parseInt(formNumber, 10),
        periodType: formType,
        startDate: formStartDate,
        endDate: formEndDate,
        facilityId: selectedFacilityId,
      }, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Financial period created.' });
      setCreateOpen(false);
      setFormName('');
      setFormStartDate('');
      setFormEndDate('');
      void periods.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to create period.' });
    } finally {
      setBusy(false);
    }
  };

  const handleClose = async (id: string) => {
    if (!confirm('Close this financial period? No new expenses can be posted after closing.')) return;
    setBusy(true);
    try {
      await enterpriseApi.closeFinancialPeriod(id, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Period closed.' });
      void periods.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to close.' });
    } finally {
      setBusy(false);
    }
  };

  const handleLock = async (id: string) => {
    if (!confirm('Lock this financial period? This is irreversible.')) return;
    setBusy(true);
    try {
      await enterpriseApi.lockFinancialPeriod(id, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Period locked.' });
      void periods.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to lock.' });
    } finally {
      setBusy(false);
    }
  };

  const statusColor = (s: string) => {
    if (s === 'open') return 'badge--success';
    if (s === 'closed') return 'badge--warning';
    if (s === 'locked') return 'badge--info';
    return 'badge--neutral';
  };

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Financial Periods</h1>
          <span className="page__sub">Fiscal period lifecycle — open, close, lock</span>
        </div>
        <div className="page__actions">
          <Button onClick={() => setCreateOpen(true)}>New Period</Button>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {periods.loading ? (
        <Spinner label="Loading periods..." />
      ) : data.length === 0 ? (
        <EmptyState title="No financial periods found" body="Create a financial period to begin tracking." />
      ) : (
        <div style={{ display: 'grid', gap: 12 }}>
          {data.map((p) => (
            <Card key={p.id} className="p-4">
              <div className="flex justify-between items-start">
                <div>
                  <div className="font-medium">{p.name}</div>
                  <div className="text-sm text-gray-500">
                    FY {p.fiscalYear} · Period {p.periodNumber} · {p.periodType}
                  </div>
                  <div className="text-sm text-gray-500">
                    {p.startDate ?? '-'} to {p.endDate ?? '-'}
                  </div>
                </div>
                <span className={`badge ${statusColor(p.status)}`}>{p.status}</span>
              </div>
              <div className="mt-2" style={{ display: 'flex', gap: 16, fontSize: 13, color: '#666' }}>
                <span>Budget: {money(p.totalBudgetMinor)}</span>
                <span>Expenses: {money(p.totalExpensesMinor)}</span>
                <span>Revenue: {money(p.totalRevenueMinor)}</span>
              </div>
              <div className="mt-2" style={{ display: 'flex', gap: 8 }}>
                {p.status === 'open' && (
                  <Button size="sm" variant="secondary" onClick={() => handleClose(p.id)} disabled={busy}>Close Period</Button>
                )}
                {p.status === 'closed' && (
                  <Button size="sm" variant="danger" onClick={() => handleLock(p.id)} disabled={busy}>Lock Period</Button>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Create Dialog */}
      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} title="New Financial Period">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <Input label="Period Name" value={formName} onChange={(e) => setFormName(e.target.value)} required />
          <div style={{ display: 'flex', gap: 8 }}>
            <Input label="Fiscal Year" value={formYear} onChange={(e) => setFormYear(e.target.value)} type="number" required style={{ flex: 1 }} />
            <Input label="Period #" value={formNumber} onChange={(e) => setFormNumber(e.target.value)} type="number" required style={{ flex: 1 }} />
          </div>
          <div>
            <label className="label">Period Type</label>
            <select value={formType} onChange={(e) => setFormType(e.target.value)} className="input" style={{ width: '100%', padding: '6px 10px' }}>
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="annual">Annual</option>
            </select>
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <Input label="Start Date" value={formStartDate} onChange={(e) => setFormStartDate(e.target.value)} type="date" required style={{ flex: 1 }} />
            <Input label="End Date" value={formEndDate} onChange={(e) => setFormEndDate(e.target.value)} type="date" required style={{ flex: 1 }} />
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button onClick={handleCreate} disabled={busy || !formName || !formStartDate || !formEndDate}>Create</Button>
          </div>
        </div>
      </Dialog>
    </div>
  );
}
