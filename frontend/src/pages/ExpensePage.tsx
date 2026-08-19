import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { enterpriseApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Dialog, EmptyState, Input, Spinner, money } from '../components/ui';
import { ApiError } from '../api/client';

/* ------------------------------------------------------------------ */
/*  Expenses (Phase 17 — Enterprise Procurement, Inventory & Finance) */
/* ------------------------------------------------------------------ */

interface Expense {
  id: string;
  referenceNumber: string;
  description: string;
  amountMinor: number;
  currency: string;
  status: string;
  expenseType: string;
  expenseDate?: string;
  paymentDate?: string;
  paymentMethod?: string;
  vendorId?: string;
  budgetId?: string;
  invoiceNumber?: string;
  category?: { id: string; name: string; code: string } | null;
  approvedAt?: string;
  rejectionReason?: string;
  notes?: string;
}

export function ExpensePage() {
  const { selectedFacilityId, organizationId } = useTenant();
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [viewExpense, setViewExpense] = useState<Expense | null>(null);
  const [filterStatus, setFilterStatus] = useState<string>('');
  const [busy, setBusy] = useState(false);

  // Form state
  const [formDescription, setFormDescription] = useState('');
  const [formAmount, setFormAmount] = useState('');
  const [formCategoryId, setFormCategoryId] = useState('');
  const [formDate, setFormDate] = useState(new Date().toISOString().slice(0, 10));
  const [formNotes, setFormNotes] = useState('');

  // Reject modal
  const [rejectId, setRejectId] = useState<string | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const params: Record<string, string> = {};
  if (filterStatus) params.status = filterStatus;

  const expenses = useFetch(
    () => organizationId ? enterpriseApi.expenses(organizationId, params, selectedFacilityId) : Promise.resolve({ data: [] }),
    [organizationId, filterStatus, selectedFacilityId],
  );

  const categories = useFetch(
    () => organizationId ? enterpriseApi.expenseCategories(organizationId, selectedFacilityId) : Promise.resolve({ data: [] }),
    [organizationId, selectedFacilityId],
  );

  const catData = (categories.data as any)?.data ?? [];
  const data: Expense[] = (expenses.data as any)?.data ?? [];

  const handleCreate = async () => {
    if (!organizationId || !formDescription || !formAmount || !formCategoryId) return;
    setBusy(true);
    try {
      await enterpriseApi.storeExpense(organizationId, {
        description: formDescription,
        amountMinor: parseInt(formAmount, 10) * 100,
        expenseCategoryId: formCategoryId,
        expenseDate: formDate,
        notes: formNotes || undefined,
        facilityId: selectedFacilityId,
      }, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Expense created.' });
      setCreateOpen(false);
      setFormDescription('');
      setFormAmount('');
      setFormNotes('');
      void expenses.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to create expense.' });
    } finally {
      setBusy(false);
    }
  };

  const handleSubmit = async (id: string) => {
    setBusy(true);
    try {
      await enterpriseApi.submitExpense(id, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Expense submitted for approval.' });
      void expenses.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to submit.' });
    } finally {
      setBusy(false);
    }
  };

  const handleApprove = async (id: string) => {
    setBusy(true);
    try {
      await enterpriseApi.approveExpense(id, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Expense approved.' });
      void expenses.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to approve.' });
    } finally {
      setBusy(false);
    }
  };

  const handleReject = async () => {
    if (!rejectId || !rejectReason) return;
    setBusy(true);
    try {
      await enterpriseApi.rejectExpense(rejectId, rejectReason, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Expense rejected.' });
      setRejectId(null);
      setRejectReason('');
      void expenses.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to reject.' });
    } finally {
      setBusy(false);
    }
  };

  const handlePay = async (id: string) => {
    setBusy(true);
    try {
      await enterpriseApi.payExpense(id, { paymentMethod: 'bank_transfer' }, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Expense marked as paid.' });
      void expenses.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to mark paid.' });
    } finally {
      setBusy(false);
    }
  };

  const handleVoid = async (id: string) => {
    if (!confirm('Void this expense? This cannot be undone.')) return;
    setBusy(true);
    try {
      await enterpriseApi.voidExpense(id, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Expense voided.' });
      void expenses.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to void.' });
    } finally {
      setBusy(false);
    }
  };

  const statusColor = (s: string) => {
    if (s === 'approved' || s === 'paid') return 'badge--success';
    if (s === 'draft') return 'badge--warning';
    if (s === 'pending_approval') return 'badge--info';
    if (s === 'rejected' || s === 'void') return 'badge--danger';
    return 'badge--neutral';
  };

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Expenses</h1>
          <span className="page__sub">Expense tracking, approval, and payment</span>
        </div>
        <div className="page__actions">
          <Button onClick={() => setCreateOpen(true)}>New Expense</Button>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <div className="page__filters" style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} className="input" style={{ padding: '6px 10px' }}>
          <option value="">All statuses</option>
          <option value="draft">Draft</option>
          <option value="pending_approval">Pending Approval</option>
          <option value="approved">Approved</option>
          <option value="paid">Paid</option>
          <option value="rejected">Rejected</option>
          <option value="void">Void</option>
        </select>
      </div>

      {expenses.loading ? (
        <Spinner label="Loading expenses..." />
      ) : data.length === 0 ? (
        <EmptyState title="No expenses found" body="Create an expense to start tracking." />
      ) : (
        <div style={{ display: 'grid', gap: 12 }}>
          {data.map((e) => (
            <Card key={e.id} className="p-4 cursor-pointer hover:shadow" onClick={() => setViewExpense(e)}>
              <div className="flex justify-between items-start">
                <div>
                  <div className="font-medium">{e.description}</div>
                  <div className="text-sm text-gray-500">
                    {e.referenceNumber} · {e.category?.name ?? '-'} · {e.expenseDate?.slice(0, 10) ?? '-'}
                  </div>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span className="font-medium">{money(e.amountMinor)}</span>
                  <span className={`badge ${statusColor(e.status)}`}>{e.status}</span>
                </div>
              </div>
              <div className="mt-2" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {e.status === 'draft' && (
                  <Button size="sm" onClick={(ev) => { ev.stopPropagation(); handleSubmit(e.id); }} disabled={busy}>Submit</Button>
                )}
                {e.status === 'pending_approval' && (
                  <>
                    <Button size="sm" onClick={(ev) => { ev.stopPropagation(); handleApprove(e.id); }} disabled={busy}>Approve</Button>
                    <Button size="sm" variant="danger" onClick={(ev) => { ev.stopPropagation(); setRejectId(e.id); }}>Reject</Button>
                  </>
                )}
                {e.status === 'approved' && (
                  <Button size="sm" onClick={(ev) => { ev.stopPropagation(); handlePay(e.id); }} disabled={busy}>Mark Paid</Button>
                )}
                {e.status !== 'void' && e.status !== 'paid' && (
                  <Button size="sm" variant="secondary" onClick={(ev) => { ev.stopPropagation(); handleVoid(e.id); }} disabled={busy}>Void</Button>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Create Dialog */}
      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} title="New Expense">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <Input label="Description" value={formDescription} onChange={(e) => setFormDescription(e.target.value)} required />
          <Input label="Amount (Rs)" value={formAmount} onChange={(e) => setFormAmount(e.target.value)} type="number" required />
          <div>
            <label className="label">Category</label>
            <select value={formCategoryId} onChange={(e) => setFormCategoryId(e.target.value)} className="input" style={{ width: '100%', padding: '6px 10px' }}>
              <option value="">Select category</option>
              {catData.map((c: any) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <Input label="Expense Date" value={formDate} onChange={(e) => setFormDate(e.target.value)} type="date" required />
          <Input label="Notes" value={formNotes} onChange={(e) => setFormNotes(e.target.value)} />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button onClick={handleCreate} disabled={busy || !formDescription || !formAmount || !formCategoryId}>Create</Button>
          </div>
        </div>
      </Dialog>

      {/* Reject Dialog */}
      <Dialog open={!!rejectId} onClose={() => setRejectId(null)} title="Reject Expense">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <Input label="Reason for rejection" value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} required />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button variant="secondary" onClick={() => setRejectId(null)}>Cancel</Button>
            <Button variant="danger" onClick={handleReject} disabled={busy || !rejectReason}>Reject</Button>
          </div>
        </div>
      </Dialog>

      {/* Detail Dialog */}
      <Dialog open={!!viewExpense} onClose={() => setViewExpense(null)} title={viewExpense?.description ?? 'Expense Detail'}>
        {viewExpense && (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div><strong>Reference:</strong> {viewExpense.referenceNumber}</div>
            <div><strong>Amount:</strong> {money(viewExpense.amountMinor)}</div>
            <div><strong>Status:</strong> <span className={`badge ${statusColor(viewExpense.status)}`}>{viewExpense.status}</span></div>
            <div><strong>Category:</strong> {viewExpense.category?.name ?? '-'}</div>
            <div><strong>Date:</strong> {viewExpense.expenseDate?.slice(0, 10) ?? '-'}</div>
            <div><strong>Payment:</strong> {viewExpense.paymentMethod ?? '-'}</div>
            {viewExpense.rejectionReason && <div style={{ gridColumn: 'span 2' }}><strong>Rejection reason:</strong> {viewExpense.rejectionReason}</div>}
            {viewExpense.notes && <div style={{ gridColumn: 'span 2' }}><strong>Notes:</strong> {viewExpense.notes}</div>}
          </div>
        )}
      </Dialog>
    </div>
  );
}
