import { api, type RequestOptions } from './client';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });
import type {
  AgingEntry, Deposit, Invoice, Settlement,
} from './types';

export const billingApi = {
  invoice: (encounterId: string, facilityId?: string | null) =>
    api.request<Invoice>(`/api/v1/encounters/${encounterId}/invoice`, { method: 'POST', body: {}, ...opt(facilityId) }),

  invoiceShow: (id: string, facilityId?: string | null) => api.request<Invoice>(`/api/v1/invoices/${id}`, opt(facilityId)),

  pay: (invoiceId: string, payload: { method: string; amountMinor: number; idempotencyKey: string; providerRef?: string }, facilityId?: string | null) =>
    api.request<{ paymentId: string; status: string; amountMinor: number; method: string; invoice: Invoice }>(
      `/api/v1/invoices/${invoiceId}/pay`,
      { method: 'POST', body: payload, ...opt(facilityId) },
    ),
};

export const financeApi = {
  deposits: (patientId: string, facilityId?: string | null) =>
    api.request<Deposit[]>(`/api/v1/patients/${patientId}/deposits`, opt(facilityId)),

  collectDeposit: (patientId: string, payload: { amountMinor: number }, facilityId?: string | null) =>
    api.request<Deposit>(`/api/v1/patients/${patientId}/deposits`, { method: 'POST', body: payload, ...opt(facilityId) }),

  aging: (patientId: string, facilityId?: string | null) =>
    api.request<AgingEntry[]>(`/api/v1/patients/${patientId}/aging`, opt(facilityId)),

  settlements: (facilityId?: string | null) =>
    api.request<Settlement[]>('/api/v1/cashier-settlements', opt(facilityId)),

  reconcileSettlement: (payload: { settlementDate: string; actualMinor: number; notes?: string }, facilityId?: string | null) =>
    api.request<Settlement>('/api/v1/cashier-settlements/reconcile', { method: 'POST', body: payload, ...opt(facilityId) }),
};

export const revenueApi = {
  summary: (orgId: string, facilityId: string, from?: string, to?: string) => {
    const params = new URLSearchParams({ facilityId });
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    return api.request<Record<string, unknown>>(`/api/v1/organizations/${orgId}/revenue/summary?${params}`);
  },
  bySource: (orgId: string, facilityId: string, from?: string, to?: string) => {
    const params = new URLSearchParams({ facilityId });
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    return api.request<Array<Record<string, unknown>>>(`/api/v1/organizations/${orgId}/revenue/by-source?${params}`);
  },
  dailyTrend: (orgId: string, facilityId: string, from?: string, to?: string) => {
    const params = new URLSearchParams({ facilityId });
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    return api.request<Array<Record<string, unknown>>>(`/api/v1/organizations/${orgId}/revenue/daily-trend?${params}`);
  },
  expenseSummary: (orgId: string, facilityId: string, from?: string, to?: string) => {
    const params = new URLSearchParams({ facilityId });
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    return api.request<Record<string, unknown>>(`/api/v1/organizations/${orgId}/revenue/expense-summary?${params}`);
  },
  aging: (orgId: string, facilityId: string) =>
    api.request<Array<Record<string, unknown>>>(`/api/v1/organizations/${orgId}/revenue/aging?facilityId=${facilityId}`),
  budgetVsActual: (budgetId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/budgets/${budgetId}/vs-actual`),
  periodSummary: (periodId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/financial-periods/${periodId}/summary`),
  receipt: (paymentId: string) =>
    api.request<Record<string, unknown> | null>(`/api/v1/payments/${paymentId}/receipt`),
  generateReceipt: (paymentId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/payments/${paymentId}/receipt`, { method: 'POST', body: {} }),
  printReceipt: (receiptId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/receipts/${receiptId}/print`, { method: 'POST', body: {} }),
  adjustments: (invoiceId: string) =>
    api.request<Array<Record<string, unknown>>>(`/api/v1/invoices/${invoiceId}/adjustments`),
  requestAdjustment: (invoiceId: string, payload: Record<string, unknown>) =>
    api.request<Record<string, unknown>>(`/api/v1/invoices/${invoiceId}/adjustments`, { method: 'POST', body: payload }),
  approveAdjustment: (adjustmentId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/billing-adjustments/${adjustmentId}/approve`, { method: 'POST', body: {} }),
  applyAdjustment: (adjustmentId: string) =>
    api.request<Record<string, unknown>>(`/api/v1/billing-adjustments/${adjustmentId}/apply`, { method: 'POST', body: {} }),
  rejectAdjustment: (adjustmentId: string, reason: string) =>
    api.request<Record<string, unknown>>(`/api/v1/billing-adjustments/${adjustmentId}/reject`, { method: 'POST', body: { reason } }),
};

export const enterpriseApi = {
  // Budgets
  budgets: (orgId: string, params?: { fiscal_year?: number; status?: string }, facilityId?: string | null) => {
    const qs = params ? '?' + new URLSearchParams(params as Record<string, string>).toString() : '';
    return api.request(`/api/v1/enterprise/organizations/${orgId}/budgets${qs}`, opt(facilityId));
  },
  storeBudget: (orgId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/budgets`, { method: 'POST', body: payload, ...opt(facilityId) }),
  showBudget: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/budgets/${id}`, opt(facilityId)),
  approveBudget: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/budgets/${id}/approve`, { method: 'POST', ...opt(facilityId) }),
  closeBudget: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/budgets/${id}/close`, { method: 'POST', ...opt(facilityId) }),
  storeBudgetLine: (id: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/budgets/${id}/lines`, { method: 'POST', body: payload, ...opt(facilityId) }),

  // Expense Categories
  expenseCategories: (orgId: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/expense-categories`, opt(facilityId)),
  storeExpenseCategory: (orgId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/expense-categories`, { method: 'POST', body: payload, ...opt(facilityId) }),

  // Expenses
  expenses: (orgId: string, params?: { status?: string; category_id?: string; budget_id?: string }, facilityId?: string | null) => {
    const qs = params ? '?' + new URLSearchParams(params as Record<string, string>).toString() : '';
    return api.request(`/api/v1/enterprise/organizations/${orgId}/expenses${qs}`, opt(facilityId));
  },
  storeExpense: (orgId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/expenses`, { method: 'POST', body: payload, ...opt(facilityId) }),
  showExpense: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}`, opt(facilityId)),
  submitExpense: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/submit`, { method: 'POST', ...opt(facilityId) }),
  approveExpense: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/approve`, { method: 'POST', ...opt(facilityId) }),
  rejectExpense: (id: string, reason: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/reject`, { method: 'POST', body: { reason }, ...opt(facilityId) }),
  payExpense: (id: string, payload: { paymentMethod: string; paymentReference?: string }, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/pay`, { method: 'POST', body: payload, ...opt(facilityId) }),
  voidExpense: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/expenses/${id}/void`, { method: 'POST', ...opt(facilityId) }),

  // Financial Periods
  financialPeriods: (orgId: string, params?: { fiscal_year?: number; status?: string }, facilityId?: string | null) => {
    const qs = params ? '?' + new URLSearchParams(params as Record<string, string>).toString() : '';
    return api.request(`/api/v1/enterprise/organizations/${orgId}/financial-periods${qs}`, opt(facilityId));
  },
  storeFinancialPeriod: (orgId: string, payload: Record<string, unknown>, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/organizations/${orgId}/financial-periods`, { method: 'POST', body: payload, ...opt(facilityId) }),
  showFinancialPeriod: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/financial-periods/${id}`, opt(facilityId)),
  closeFinancialPeriod: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/financial-periods/${id}/close`, { method: 'POST', ...opt(facilityId) }),
  lockFinancialPeriod: (id: string, facilityId?: string | null) =>
    api.request(`/api/v1/enterprise/financial-periods/${id}/lock`, { method: 'POST', ...opt(facilityId) }),
};
