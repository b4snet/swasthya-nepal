import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { enterpriseApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Dialog, EmptyState, Input, Spinner, money } from '../components/ui';
import { ApiError } from '../api/client';

/* ------------------------------------------------------------------ */
/*  Budgets (Phase 17 — Enterprise Procurement, Inventory & Finance)  */
/* ------------------------------------------------------------------ */

interface BudgetLine {
  id: string;
  description: string;
  allocationMinor: number;
  spentMinor: number;
  committedMinor: number;
  remainingMinor: number;
  category?: string;
}

interface Budget {
  id: string;
  budgetCode: string;
  name: string;
  description?: string;
  budgetType: string;
  fiscalYear: number;
  status: string;
  totalAllocationMinor: number;
  spentMinor: number;
  committedMinor: number;
  remainingMinor: number;
  utilizationPercent: number;
  approvedAt?: string;
  closedAt?: string;
  lines: BudgetLine[];
}

export function BudgetPage() {
  const { selectedFacilityId } = useTenant();
  const { organizationId } = useTenant();
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [viewBudget, setViewBudget] = useState<Budget | null>(null);
  const [filterYear, setFilterYear] = useState<string>('');
  const [filterStatus, setFilterStatus] = useState<string>('');
  const [busy, setBusy] = useState(false);

  // Form state
  const [formName, setFormName] = useState('');
  const [formYear, setFormYear] = useState(new Date().getFullYear().toString());
  const [formType, setFormType] = useState('operational');
  const [formAllocation, setFormAllocation] = useState('');
  const [formDescription, setFormDescription] = useState('');

  const params: Record<string, string> = {};
  if (filterYear) params.fiscal_year = filterYear;
  if (filterStatus) params.status = filterStatus;

  const budgets = useFetch(
    () => organizationId ? enterpriseApi.budgets(organizationId, params, selectedFacilityId) : Promise.resolve({ data: [] }),
    [organizationId, filterYear, filterStatus, selectedFacilityId],
  );

  const data: Budget[] = (budgets.data as any)?.data ?? [];

  const handleCreate = async () => {
    if (!organizationId || !formName || !formYear || !formAllocation) return;
    setBusy(true);
    try {
      await enterpriseApi.storeBudget(organizationId, {
        name: formName,
        fiscalYear: parseInt(formYear, 10),
        budgetType: formType,
        totalAllocationMinor: parseInt(formAllocation, 10) * 100,
        description: formDescription || undefined,
        facilityId: selectedFacilityId,
      }, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Budget created.' });
      setCreateOpen(false);
      setFormName('');
      setFormAllocation('');
      setFormDescription('');
      void budgets.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to create budget.' });
    } finally {
      setBusy(false);
    }
  };

  const handleApprove = async (id: string) => {
    setBusy(true);
    try {
      await enterpriseApi.approveBudget(id, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Budget approved and activated.' });
      void budgets.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to approve.' });
    } finally {
      setBusy(false);
    }
  };

  const handleClose = async (id: string) => {
    setBusy(true);
    try {
      await enterpriseApi.closeBudget(id, selectedFacilityId);
      setNotice({ tone: 'success', text: 'Budget closed.' });
      void budgets.refresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Failed to close.' });
    } finally {
      setBusy(false);
    }
  };

  const statusColor = (s: string) => {
    if (s === 'active') return 'badge--success';
    if (s === 'draft') return 'badge--warning';
    if (s === 'closed') return 'badge--info';
    return 'badge--neutral';
  };

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Budgets</h1>
          <span className="page__sub">Budget allocation, tracking, and utilization</span>
        </div>
        <div className="page__actions">
          <Button onClick={() => setCreateOpen(true)}>New Budget</Button>
        </div>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <div className="page__filters" style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        <Input
          label="Fiscal year"
          placeholder="Fiscal year"
          value={filterYear}
          onChange={(e) => setFilterYear(e.target.value)}
          style={{ width: 120 }}
        />
        <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} className="input" style={{ padding: '6px 10px' }}>
          <option value="">All statuses</option>
          <option value="draft">Draft</option>
          <option value="active">Active</option>
          <option value="closed">Closed</option>
          <option value="archived">Archived</option>
        </select>
      </div>

      {budgets.loading ? (
        <Spinner label="Loading budgets..." />
      ) : data.length === 0 ? (
        <EmptyState title="No budgets found" body="Create a budget to start tracking allocations." />
      ) : (
        <div style={{ display: 'grid', gap: 12 }}>
          {data.map((b) => (
            <Card key={b.id} className="p-4 cursor-pointer hover:shadow" onClick={() => setViewBudget(b)}>
              <div className="flex justify-between items-start">
                <div>
                  <div className="font-medium">{b.name}</div>
                  <div className="text-sm text-gray-500">
                    {b.budgetCode} · FY {b.fiscalYear} · {b.budgetType}
                  </div>
                </div>
                <span className={`badge ${statusColor(b.status)}`}>{b.status}</span>
              </div>
              <div className="mt-2" style={{ display: 'flex', gap: 16, fontSize: 13, color: '#666' }}>
                <span>Allocation: {money(b.totalAllocationMinor)}</span>
                <span>Spent: {money(b.spentMinor)}</span>
                <span>Remaining: {money(b.remainingMinor)}</span>
                <span>Utilization: {b.utilizationPercent}%</span>
              </div>
              {b.status === 'draft' && (
                <div className="mt-2" style={{ display: 'flex', gap: 8 }}>
                  <Button size="sm" onClick={(e) => { e.stopPropagation(); handleApprove(b.id); }} disabled={busy}>Approve</Button>
                </div>
              )}
              {b.status === 'active' && (
                <div className="mt-2" style={{ display: 'flex', gap: 8 }}>
                  <Button size="sm" variant="secondary" onClick={(e) => { e.stopPropagation(); handleClose(b.id); }} disabled={busy}>Close</Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      {/* Create Dialog */}
      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} title="Create Budget">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <Input label="Budget Name" value={formName} onChange={(e) => setFormName(e.target.value)} required />
          <Input label="Fiscal Year" value={formYear} onChange={(e) => setFormYear(e.target.value)} type="number" required />
          <div>
            <label className="label">Budget Type</label>
            <select value={formType} onChange={(e) => setFormType(e.target.value)} className="input" style={{ width: '100%', padding: '6px 10px' }}>
              <option value="operational">Operational</option>
              <option value="capital">Capital</option>
              <option value="project">Project</option>
            </select>
          </div>
          <Input label="Total Allocation (Rs)" value={formAllocation} onChange={(e) => setFormAllocation(e.target.value)} type="number" required />
          <Input label="Description" value={formDescription} onChange={(e) => setFormDescription(e.target.value)} />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button onClick={handleCreate} disabled={busy || !formName || !formAllocation}>Create</Button>
          </div>
        </div>
      </Dialog>

      {/* Detail Dialog */}
      <Dialog open={!!viewBudget} onClose={() => setViewBudget(null)} title={viewBudget?.name ?? 'Budget Detail'}>
        {viewBudget && (
          <div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 16 }}>
              <div><strong>Code:</strong> {viewBudget.budgetCode}</div>
              <div><strong>Type:</strong> {viewBudget.budgetType}</div>
              <div><strong>FY:</strong> {viewBudget.fiscalYear}</div>
              <div><strong>Status:</strong> <span className={`badge ${statusColor(viewBudget.status)}`}>{viewBudget.status}</span></div>
              <div><strong>Allocation:</strong> {money(viewBudget.totalAllocationMinor)}</div>
              <div><strong>Spent:</strong> {money(viewBudget.spentMinor)}</div>
              <div><strong>Committed:</strong> {money(viewBudget.committedMinor)}</div>
              <div><strong>Remaining:</strong> {money(viewBudget.remainingMinor)}</div>
            </div>
            {viewBudget.lines.length > 0 && (
              <div>
                <h3 style={{ marginBottom: 8 }}>Budget Lines</h3>
                <table style={{ width: '100%', fontSize: 13 }}>
                  <thead>
                    <tr><th>Description</th><th>Category</th><th>Allocation</th><th>Spent</th><th>Remaining</th></tr>
                  </thead>
                  <tbody>
                    {viewBudget.lines.map((l) => (
                      <tr key={l.id}>
                        <td>{l.description}</td>
                        <td>{l.category ?? '-'}</td>
                        <td>{money(l.allocationMinor)}</td>
                        <td>{money(l.spentMinor)}</td>
                        <td>{money(l.remainingMinor)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </Dialog>
    </div>
  );
}
