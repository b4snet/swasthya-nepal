import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { api } from '../api/client';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import {
  Calendar,
  Receipt,
  Shield,
  FileText,
  Building2,
} from 'lucide-react';

/* ── API Client ──────────────────────────────────────────────────── */

const opt = (facilityId?: string | null) => ({ facilityId } as Record<string, unknown>);

const nepalFinanceApi = {
  // Fiscal Years
  fiscalYears: (fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/finance/fiscal-years`, opt(fac)).catch(() => []),
  storeFiscalYear: (payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>('/api/v1/finance/fiscal-years', { method: 'POST', body: payload, ...opt(fac) }),
  closeFiscalYear: (id: string, fac?: string | null) =>
    api.request<unknown>(`/api/v1/finance/fiscal-years/${id}/close`, { method: 'POST', body: {}, ...opt(fac) }),

  // Tax Rules
  taxRules: (fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/finance/tax-rules`, opt(fac)).catch(() => []),
  storeTaxRule: (payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>('/api/v1/finance/tax-rules', { method: 'POST', body: payload, ...opt(fac) }),

  // Payers
  payers: (fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/finance/payers`, opt(fac)).catch(() => []),
  storePayer: (payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>('/api/v1/finance/payers', { method: 'POST', body: payload, ...opt(fac) }),

  // Benefit Rules
  benefitRules: (payerId: string, fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/finance/payers/${payerId}/benefit-rules`, opt(fac)).catch(() => []),
  storeBenefitRule: (payerId: string, payload: Record<string, unknown>, fac?: string | null) =>
    api.request<unknown>(`/api/v1/finance/payers/${payerId}/benefit-rules`, { method: 'POST', body: payload, ...opt(fac) }),

  // Claims
  claims: (fac?: string | null) =>
    api.request<unknown[]>(`/api/v1/finance/claims`, opt(fac)).catch(() => []),
};

/* ── Types ───────────────────────────────────────────────────────── */

interface FiscalYear {
  id: string;
  name: string;
  calendar_type: string;
  nepal_fiscal_year: string | null;
  fiscal_year: number;
  start_date: string;
  end_date: string;
  status: string;
  period_status: string;
}

interface TaxRule {
  id: string;
  code: string;
  name: string;
  taxType: string;
  rateMethod: string;
  rateValueBps: number;
  effectiveFrom: string;
  effectiveTo: string | null;
  status: string;
  sourceAuthority: string | null;
  sourceDocument: string | null;
}

interface Payer {
  id: string;
  name: string;
  code: string;
  payer_type: string;
  payer_sub_type: string | null;
  status: string;
  scheme_version: string | null;
}

interface BenefitRule {
  id: string;
  code: string;
  name: string;
  schemeVersion: string;
  serviceCategory: string;
  coverageType: string;
  coveragePercentBps: number | null;
  limitMinor: number | null;
  copayMinor: number | null;
  effectiveFrom: string;
  effectiveTo: string | null;
  status: string;
}

/* ── Constants ───────────────────────────────────────────────────── */

const TAX_TYPES = [
  { value: 'vat', label: 'VAT (Value Added Tax)' },
  { value: 'health_service_tax', label: 'Health Service Tax' },
  { value: 'health_equity_fee', label: 'Health Equity Fee' },
  { value: 'excise', label: 'Excise Duty' },
  { value: 'other', label: 'Other Tax' },
];

const PAYER_SUB_TYPES = [
  { value: 'ssf', label: 'Social Security Fund (SSF)' },
  { value: 'hib', label: 'Health Insurance Board (HIB)' },
  { value: 'private', label: 'Private Insurance' },
  { value: 'corporate', label: 'Corporate / Sponsor' },
  { value: 'government', label: 'Government Scheme' },
  { value: 'other', label: 'Other Payer' },
];

const SERVICE_CATEGORIES = [
  { value: 'opd', label: 'Outpatient (OPD)' },
  { value: 'ipd', label: 'Inpatient (IPD)' },
  { value: 'medicine', label: 'Medicine / Pharmacy' },
  { value: 'diagnostic', label: 'Diagnostic (Lab/Radio)' },
  { value: 'surgery', label: 'Surgery / Procedure' },
  { value: 'maternity', label: 'Maternity' },
  { value: 'emergency', label: 'Emergency' },
];

const COVERAGE_TYPES = [
  { value: 'full', label: 'Full Coverage' },
  { value: 'co_pay', label: 'Co-payment' },
  { value: 'deductible', label: 'Deductible' },
  { value: 'capped', label: 'Capped (Annual Limit)' },
  { value: 'excluded', label: 'Excluded' },
];

const STATUS_BADGES: Record<string, { label: string; color: string; bg: string }> = {
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  inactive: { label: 'Inactive', color: '#6b7280', bg: '#f3f4f6' },
  superseded: { label: 'Superseded', color: '#f59e0b', bg: '#fef3c7' },
  open: { label: 'Open', color: '#10b981', bg: '#ecfdf5' },
  closed: { label: 'Closed', color: '#6b7280', bg: '#f3f4f6' },
  locked: { label: 'Locked', color: '#ef4444', bg: '#fee2e2' },
};

function StatusBadge({ status }: { status: string }) {
  const c = STATUS_BADGES[status] ?? { label: status, color: '#6b7280', bg: '#f3f4f6' };
  return <span className="ai-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>;
}

function formatNpr(amountMinor: number): string {
  return `NPR ${(amountMinor / 100).toLocaleString('en-NP', { minimumFractionDigits: 2 })}`;
}

function formatBps(bps: number): string {
  return `${(bps / 100).toFixed(2)}%`;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function NepalFinanceAdminPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'fiscal' | 'tax' | 'payers' | 'benefits' | 'claims'>('fiscal');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [selectedPayerId, setSelectedPayerId] = useState<string | null>(null);

  // Forms
  const [fiscalForm, setFiscalForm] = useState({ name: '', fiscalYear: '', startDate: '', endDate: '', calendarType: 'nepal_fiscal', nepalFiscalYear: '' });
  const [taxForm, setTaxForm] = useState({ code: '', name: '', taxType: 'vat', rateMethod: 'percentage', rateValueBps: 1300, effectiveFrom: '', sourceAuthority: '', sourceDocument: '' });
  const [payerForm, setPayerForm] = useState({ name: '', code: '', payerType: 'insurance', payerSubType: 'private', schemeVersion: '' });
  const [benefitForm, setBenefitForm] = useState({ code: '', name: '', schemeVersion: '', serviceCategory: 'opd', coverageType: 'full', coveragePercentBps: 10000, limitMinor: 0, copayMinor: 0, effectiveFrom: '' });

  // Data
  const fiscalYears = useFetch(() => nepalFinanceApi.fiscalYears(fac), [fac]);
  const taxRules = useFetch(() => nepalFinanceApi.taxRules(fac), [fac]);
  const payers = useFetch(() => nepalFinanceApi.payers(fac), [fac]);
  const benefitRules = useFetch(
    () => selectedPayerId ? nepalFinanceApi.benefitRules(selectedPayerId, fac) : Promise.resolve([]),
    [selectedPayerId, fac],
  );
  const claims = useFetch(() => nepalFinanceApi.claims(fac), [fac]);

  const allFiscalYears = useMemo(() => (fiscalYears.data ?? []) as FiscalYear[], [fiscalYears.data]);
  const allTaxRules = useMemo(() => (taxRules.data ?? []) as TaxRule[], [taxRules.data]);
  const allPayers = useMemo(() => (payers.data ?? []) as Payer[], [payers.data]);
  const allBenefitRules = useMemo(() => (benefitRules.data ?? []) as BenefitRule[], [benefitRules.data]);
  const allClaims = useMemo(() => (claims.data ?? []) as unknown[], [claims.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Census
  const activeTaxRules = allTaxRules.filter(r => r.status === 'active').length;
  const ssfPayers = allPayers.filter(p => p.payer_sub_type === 'ssf').length;
  const hibPayers = allPayers.filter(p => p.payer_sub_type === 'hib').length;

  return (
    <div className="page nepal-finance-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Nepal Financial Administration</h1>
          <p className="page__subtitle">Fiscal year, tax/VAT, payers (SSF/HIB), benefit rules, claims</p>
        </div>
        <div className="ai-actions">
          <Button variant="ghost" onClick={() => { fiscalYears.refresh(); taxRules.refresh(); payers.refresh(); benefitRules.refresh(); claims.refresh(); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Governance Banner ─────────────────────────────── */}
      <Alert tone="warning">
        <strong>Nepal Financial Rules:</strong> All statutory values (tax rates, SSF/HIB benefit limits) are configurable and effective-dated. Historical records use the rules that were active at posting time. Never hard-code mutable Nepal statutory values.
      </Alert>

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="ai-census">
        <div className="ai-census-card">
          <span className="ai-census-value">{allFiscalYears.length}</span>
          <span className="ai-census-label">Fiscal Years</span>
        </div>
        <div className="ai-census-card">
          <span className="ai-census-value" style={{ color: '#10b981' }}>{activeTaxRules}</span>
          <span className="ai-census-label">Active Tax Rules</span>
        </div>
        <div className="ai-census-card">
          <span className="ai-census-value">{allPayers.length}</span>
          <span className="ai-census-label">Total Payers</span>
        </div>
        <div className="ai-census-card">
          <span className="ai-census-value" style={{ color: '#3b82f6' }}>{ssfPayers}</span>
          <span className="ai-census-label">SSF Payers</span>
        </div>
        <div className="ai-census-card">
          <span className="ai-census-value" style={{ color: '#8b5cf6' }}>{hibPayers}</span>
          <span className="ai-census-label">HIB Payers</span>
        </div>
        <div className="ai-census-card">
          <span className="ai-census-value">{allBenefitRules.length}</span>
          <span className="ai-census-label">Benefit Rules</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="ai-tabs">
        {(['fiscal', 'tax', 'payers', 'benefits', 'claims'] as const).map(t => (
          <button key={t} className={`ai-tab ${activeTab === t ? 'ai-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'fiscal' ? 'Fiscal Years' : t === 'tax' ? 'Tax / VAT' : t === 'payers' ? 'Payers (SSF/HIB)' : t === 'benefits' ? 'Benefit Rules' : 'Claims'}
          </button>
        ))}
      </div>

      {/* ── Fiscal Years Tab ──────────────────────────────── */}
      {activeTab === 'fiscal' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3><Calendar size={18} /> Nepal Fiscal Year</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-fiscal')}>+ Create Period</Button>
          </div>
          <Alert tone="info">
            Nepal fiscal year runs from mid-July to mid-July (Shrawan 1 to Chaitra 30/31 in BS calendar).
            Current fiscal year: <strong>2082/83 BS</strong> (July 16, 2025 – July 15, 2026).
          </Alert>
          {allFiscalYears.length === 0 ? (
            <EmptyState title="No fiscal periods" body="Create fiscal periods for your organization's financial year." />
          ) : (
            <div className="ai-table">
              <div className="ai-table-header">
                <span>Name</span>
                <span>Fiscal Year</span>
                <span>BS Year</span>
                <span>Start</span>
                <span>End</span>
                <span>Status</span>
                <span>Actions</span>
              </div>
              {allFiscalYears.map(fy => (
                <div key={fy.id} className="ai-table-row">
                  <span className="ai-name">{fy.name}</span>
                  <span>{fy.fiscal_year}</span>
                  <span className="ai-mono">{fy.nepal_fiscal_year ?? '—'}</span>
                  <span>{fy.start_date}</span>
                  <span>{fy.end_date}</span>
                  <StatusBadge status={fy.period_status} />
                  <span>
                    {fy.period_status === 'open' && (
                      <Button variant="ghost" size="sm" onClick={() => void go(() => nepalFinanceApi.closeFiscalYear(fy.id, fac).then(() => fiscalYears.refresh()))}>Close</Button>
                    )}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Tax Rules Tab ─────────────────────────────────── */}
      {activeTab === 'tax' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3><Receipt size={18} /> Tax / VAT Configuration</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-tax')}>+ Add Tax Rule</Button>
          </div>
          <Alert tone="info">
            Nepal tax rates are effective-dated. Changing today's rule does NOT rewrite historical invoices.
            Each charge references the tax rule active at posting time.
          </Alert>
          {allTaxRules.length === 0 ? (
            <EmptyState title="No tax rules configured" body="Add tax rules for VAT, health service tax, and other applicable taxes." />
          ) : (
            <div className="ai-table">
              <div className="ai-table-header">
                <span>Code</span>
                <span>Name</span>
                <span>Type</span>
                <span>Rate</span>
                <span>Effective From</span>
                <span>Effective To</span>
                <span>Source</span>
                <span>Status</span>
              </div>
              {allTaxRules.map(rule => (
                <div key={rule.id} className="ai-table-row">
                  <span className="ai-mono">{rule.code}</span>
                  <span className="ai-name">{rule.name}</span>
                  <span>{rule.taxType.replace(/_/g, ' ')}</span>
                  <span className="ai-mono">{formatBps(rule.rateValueBps)}</span>
                  <span>{rule.effectiveFrom}</span>
                  <span>{rule.effectiveTo ?? 'Current'}</span>
                  <span className="ai-mono" title={rule.sourceDocument ?? ''}>{rule.sourceAuthority ?? '—'}</span>
                  <StatusBadge status={rule.status} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Payers Tab ────────────────────────────────────── */}
      {activeTab === 'payers' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3><Building2 size={18} /> Payers (SSF / HIB / Private / Corporate)</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-payer')}>+ Add Payer</Button>
          </div>
          <Alert tone="info">
            All payers use the shared claims engine. SSF and HIB are payer configurations, not separate billing systems.
            Configure benefit rules per payer to define coverage.
          </Alert>
          {allPayers.length === 0 ? (
            <EmptyState title="No payers configured" body="Add payers for SSF, HIB, private insurance, and corporate sponsors." />
          ) : (
            <div className="ai-table">
              <div className="ai-table-header">
                <span>Code</span>
                <span>Name</span>
                <span>Type</span>
                <span>Sub-Type</span>
                <span>Scheme</span>
                <span>Status</span>
                <span>Benefits</span>
              </div>
              {allPayers.map(p => (
                <div key={p.id} className="ai-table-row">
                  <span className="ai-mono">{p.code}</span>
                  <span className="ai-name">{p.name}</span>
                  <span>{p.payer_type}</span>
                  <span>{p.payer_sub_type ?? '—'}</span>
                  <span className="ai-mono">{p.scheme_version ?? '—'}</span>
                  <StatusBadge status={p.status} />
                  <span>
                    <Button variant="ghost" size="sm" onClick={() => { setSelectedPayerId(p.id); setActiveTab('benefits'); }}>View Rules</Button>
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Benefit Rules Tab ─────────────────────────────── */}
      {activeTab === 'benefits' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3><Shield size={18} /> Benefit Rules</h3>
            {selectedPayerId && (
              <Button variant="primary" size="sm" onClick={() => setDlg('new-benefit')}>+ Add Rule</Button>
            )}
          </div>
          {!selectedPayerId ? (
            <EmptyState title="Select a payer" body="Click 'View Rules' on a payer to see its benefit rules." />
          ) : allBenefitRules.length === 0 ? (
            <EmptyState title="No benefit rules" body="Add benefit rules to define coverage for this payer's schemes." />
          ) : (
            <div className="ai-table">
              <div className="ai-table-header">
                <span>Code</span>
                <span>Name</span>
                <span>Service</span>
                <span>Coverage</span>
                <span>Limit</span>
                <span>Effective</span>
                <span>Status</span>
              </div>
              {allBenefitRules.map(br => (
                <div key={br.id} className="ai-table-row">
                  <span className="ai-mono">{br.code}</span>
                  <span className="ai-name">{br.name}</span>
                  <span>{br.serviceCategory}</span>
                  <span>{br.coverageType.replace(/_/g, ' ')}</span>
                  <span>{br.limitMinor ? formatNpr(br.limitMinor) : '—'}</span>
                  <span>{br.effectiveFrom} → {br.effectiveTo ?? 'Current'}</span>
                  <StatusBadge status={br.status} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Claims Tab ────────────────────────────────────── */}
      {activeTab === 'claims' && (
        <Card className="ai-section-card">
          <div className="ai-section-header">
            <h3><FileText size={18} /> Claims (SSF / HIB / Private)</h3>
          </div>
          {allClaims.length === 0 ? (
            <EmptyState title="No claims" body="Claims are created from invoices when a patient has payer coverage." />
          ) : (
            <div className="ai-table">
              <div className="ai-table-header">
                <span>Claim #</span>
                <span>Type</span>
                <span>Payer</span>
                <span>Status</span>
                <span>Billed</span>
                <span>Settlement</span>
              </div>
              {(allClaims as any[]).map((c: any) => (
                <div key={c.id} className="ai-table-row">
                  <span className="ai-mono">{c.claim_number}</span>
                  <span>{c.claim_type ?? 'standard'}</span>
                  <span>{c.payer_id}</span>
                  <StatusBadge status={c.status} />
                  <span>{formatNpr(c.billed_total_minor ?? 0)}</span>
                  <span>{formatNpr(c.settlement_minor ?? 0)}</span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* New Fiscal Year */}
      {dlg === 'new-fiscal' && (
        <Dialog open onClose={() => setDlg(null)} title="Create Fiscal Period" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              await go(() => nepalFinanceApi.storeFiscalYear(fiscalForm, fac).then(() => fiscalYears.refresh()));
              setDlg(null);
            }} loading={busy} disabled={!fiscalForm.name || !fiscalForm.startDate}>Create</Button>
          </>
        }>
          <form className="ai-form">
            <Input label="Period Name" value={fiscalForm.name} onChange={e => setFiscalForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. FY 2082/83" required />
            <Input label="Fiscal Year" value={fiscalForm.fiscalYear} onChange={e => setFiscalForm(f => ({ ...f, fiscalYear: e.target.value }))} placeholder="e.g. 2082" type="number" required />
            <Input label="Nepali BS Year" value={fiscalForm.nepalFiscalYear} onChange={e => setFiscalForm(f => ({ ...f, nepalFiscalYear: e.target.value }))} placeholder="e.g. 2082/83" />
            <Input label="Calendar Type" value={fiscalForm.calendarType} onChange={e => setFiscalForm(f => ({ ...f, calendarType: e.target.value }))} />
            <Input label="Start Date" value={fiscalForm.startDate} onChange={e => setFiscalForm(f => ({ ...f, startDate: e.target.value }))} type="date" required />
            <Input label="End Date" value={fiscalForm.endDate} onChange={e => setFiscalForm(f => ({ ...f, endDate: e.target.value }))} type="date" required />
            <Alert tone="info">Nepal fiscal year: July 16 to July 15 (mid-Shrawan to mid-Shrawan in BS).</Alert>
          </form>
        </Dialog>
      )}

      {/* New Tax Rule */}
      {dlg === 'new-tax' && (
        <Dialog open onClose={() => setDlg(null)} title="Add Tax Rule" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              await go(() => nepalFinanceApi.storeTaxRule(taxForm, fac).then(() => taxRules.refresh()));
              setDlg(null);
            }} loading={busy} disabled={!taxForm.code || !taxForm.name}>Add Rule</Button>
          </>
        }>
          <form className="ai-form">
            <Input label="Rule Code" value={taxForm.code} onChange={e => setTaxForm(f => ({ ...f, code: e.target.value }))} placeholder="e.g. VAT_13" required />
            <Input label="Rule Name" value={taxForm.name} onChange={e => setTaxForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. Standard VAT 13%" required />
            <div className="ai-form-field">
              <label className="ai-label">Tax Type</label>
              <select className="ai-input" value={taxForm.taxType} onChange={e => setTaxForm(f => ({ ...f, taxType: e.target.value }))}>
                {TAX_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
            <Input label="Rate (basis points)" value={taxForm.rateValueBps} onChange={e => setTaxForm(f => ({ ...f, rateValueBps: parseInt(e.target.value) || 0 }))} type="number" hint="1300 = 13.00%, 500 = 5.00%" required />
            <Input label="Effective From" value={taxForm.effectiveFrom} onChange={e => setTaxForm(f => ({ ...f, effectiveFrom: e.target.value }))} type="date" required />
            <Input label="Source Authority" value={taxForm.sourceAuthority} onChange={e => setTaxForm(f => ({ ...f, sourceAuthority: e.target.value }))} placeholder="e.g. Inland Revenue Department" />
            <Input label="Source Document" value={taxForm.sourceDocument} onChange={e => setTaxForm(f => ({ ...f, sourceDocument: e.target.value }))} placeholder="e.g. Finance Act 2082/83" />
            <Alert tone="warning">Historical records use the tax rule active at posting time. Creating a new rule does NOT change past invoices.</Alert>
          </form>
        </Dialog>
      )}

      {/* New Payer */}
      {dlg === 'new-payer' && (
        <Dialog open onClose={() => setDlg(null)} title="Add Payer" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              await go(() => nepalFinanceApi.storePayer(payerForm, fac).then(() => payers.refresh()));
              setDlg(null);
            }} loading={busy} disabled={!payerForm.name || !payerForm.code}>Add Payer</Button>
          </>
        }>
          <form className="ai-form">
            <Input label="Payer Code" value={payerForm.code} onChange={e => setPayerForm(f => ({ ...f, code: e.target.value }))} placeholder="e.g. SSF" required />
            <Input label="Payer Name" value={payerForm.name} onChange={e => setPayerForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. Social Security Fund" required />
            <div className="ai-form-field">
              <label className="ai-label">Payer Sub-Type</label>
              <select className="ai-input" value={payerForm.payerSubType} onChange={e => setPayerForm(f => ({ ...f, payerSubType: e.target.value }))}>
                {PAYER_SUB_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
            <Input label="Scheme Version" value={payerForm.schemeVersion} onChange={e => setPayerForm(f => ({ ...f, schemeVersion: e.target.value }))} placeholder="e.g. SSF_2082, HIB_BP_V3" />
            <Alert tone="info">SSF and HIB use the same claims engine. Configure benefit rules after creating the payer.</Alert>
          </form>
        </Dialog>
      )}

      {/* New Benefit Rule */}
      {dlg === 'new-benefit' && (
        <Dialog open onClose={() => setDlg(null)} title="Add Benefit Rule" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!selectedPayerId) return;
              await go(() => nepalFinanceApi.storeBenefitRule(selectedPayerId, benefitForm, fac).then(() => benefitRules.refresh()));
              setDlg(null);
            }} loading={busy} disabled={!benefitForm.code || !benefitForm.name}>Add Rule</Button>
          </>
        }>
          <form className="ai-form">
            <Input label="Rule Code" value={benefitForm.code} onChange={e => setBenefitForm(f => ({ ...f, code: e.target.value }))} placeholder="e.g. SSF_OPD_MEDICINE" required />
            <Input label="Rule Name" value={benefitForm.name} onChange={e => setBenefitForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. SSF OPD Medicine Coverage" required />
            <Input label="Scheme Version" value={benefitForm.schemeVersion} onChange={e => setBenefitForm(f => ({ ...f, schemeVersion: e.target.value }))} placeholder="e.g. SSF_2082" required />
            <div className="ai-form-field">
              <label className="ai-label">Service Category</label>
              <select className="ai-input" value={benefitForm.serviceCategory} onChange={e => setBenefitForm(f => ({ ...f, serviceCategory: e.target.value }))}>
                {SERVICE_CATEGORIES.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
              </select>
            </div>
            <div className="ai-form-field">
              <label className="ai-label">Coverage Type</label>
              <select className="ai-input" value={benefitForm.coverageType} onChange={e => setBenefitForm(f => ({ ...f, coverageType: e.target.value }))}>
                {COVERAGE_TYPES.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
              </select>
            </div>
            <Input label="Coverage %" value={benefitForm.coveragePercentBps} onChange={e => setBenefitForm(f => ({ ...f, coveragePercentBps: parseInt(e.target.value) || 0 }))} type="number" hint="10000 = 100%, 7500 = 75%" />
            <Input label="Annual Limit (NPR minor)" value={benefitForm.limitMinor} onChange={e => setBenefitForm(f => ({ ...f, limitMinor: parseInt(e.target.value) || 0 }))} type="number" hint="10000000 = NPR 100,000" />
            <Input label="Co-payment (NPR minor)" value={benefitForm.copayMinor} onChange={e => setBenefitForm(f => ({ ...f, copayMinor: parseInt(e.target.value) || 0 }))} type="number" />
            <Input label="Effective From" value={benefitForm.effectiveFrom} onChange={e => setBenefitForm(f => ({ ...f, effectiveFrom: e.target.value }))} type="date" required />
            <Alert tone="warning">Benefit rules are effective-dated. Historical claims use the rule active at claim time.</Alert>
          </form>
        </Dialog>
      )}
    </div>
  );
}
