import { useState, useEffect, useCallback } from 'react';
import { useTenant } from '../context/TenantContext';
import { revenueApi } from '../api/endpoints';
import { Button, EmptyState } from '../components/ui';
import { useI18n } from '../i18n/I18nProvider';
import {
  DollarSign,
  TrendingUp,
  TrendingDown,
  Clock,
  Receipt,
  AlertTriangle,
  CheckCircle,
  ArrowDownRight,
  RefreshCw,
  Filter,
} from 'lucide-react';
import './revenue-cycle.css';

type Tab = 'overview' | 'trends' | 'aging' | 'adjustments' | 'receipts';

export function RevenueCyclePage() {
  const { organizationId, selectedFacilityId: facilityId } = useTenant();
  const { } = useI18n();

  const [activeTab, setActiveTab] = useState<Tab>('overview');
  const [loading, setLoading] = useState(true);
  const [summary, setSummary] = useState<Record<string, unknown> | null>(null);
  const [bySource, setBySource] = useState<Array<Record<string, unknown>>>([]);
  const [dailyTrend, setDailyTrend] = useState<Array<Record<string, unknown>>>([]);
  const [expenseSummary, setExpenseSummary] = useState<Record<string, unknown> | null>(null);
  const [agingData, setAgingData] = useState<Array<Record<string, unknown>>>([]);
  const [dateFrom, setDateFrom] = useState(() => {
    const d = new Date();
    d.setDate(1);
    return d.toISOString().split('T')[0];
  });
  const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);

  const fetchAll = useCallback(async () => {
    if (!organizationId || !facilityId) return;
    setLoading(true);
    try {
      const [sum, src, trend, exp, age] = await Promise.all([
        revenueApi.summary(organizationId, facilityId, dateFrom, dateTo),
        revenueApi.bySource(organizationId, facilityId, dateFrom, dateTo),
        revenueApi.dailyTrend(organizationId, facilityId, dateFrom, dateTo),
        revenueApi.expenseSummary(organizationId, facilityId, dateFrom, dateTo),
        revenueApi.aging(organizationId, facilityId),
      ]);
      setSummary(sum as unknown as Record<string, unknown>);
      setBySource(src as unknown as Array<Record<string, unknown>>);
      setDailyTrend(trend as unknown as Array<Record<string, unknown>>);
      setExpenseSummary(exp as unknown as Record<string, unknown>);
      setAgingData(age as unknown as Array<Record<string, unknown>>);
    } catch {
      // Errors handled per-section
    } finally {
      setLoading(false);
    }
  }, [organizationId, facilityId, dateFrom, dateTo]);

  useEffect(() => {
    fetchAll();
  }, [fetchAll]);

  const formatCurrency = (minor: number) =>
    `Rs. ${(minor / 100).toLocaleString('en-NP', { minimumFractionDigits: 0 })}`;

  const tabs: { key: Tab; label: string; icon: typeof DollarSign }[] = [
    { key: 'overview', label: 'Overview', icon: DollarSign },
    { key: 'trends', label: 'Trends', icon: TrendingUp },
    { key: 'aging', label: 'Aging', icon: Clock },
    { key: 'adjustments', label: 'Adjustments', icon: ArrowDownRight },
    { key: 'receipts', label: 'Receipts', icon: Receipt },
  ];

  return (
    <div className="rc-page">
      {/* Header */}
      <header className="rc-header">
        <div className="rc-header__title">
          <DollarSign size={24} />
          <div>
            <h1>Revenue Cycle</h1>
            <p className="rc-header__subtitle">Financial overview, reports, and billing management</p>
          </div>
        </div>
        <div className="rc-header__controls">
          <div className="rc-date-range">
            <Filter size={16} />
            <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
            <span>to</span>
            <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          </div>
          <Button onClick={fetchAll} variant="ghost" size="sm">
            <RefreshCw size={16} />
          </Button>
        </div>
      </header>

      {/* Tabs */}
      <div className="rc-tabs">
        {tabs.map((tab) => {
          const Icon = tab.icon;
          return (
            <button
              key={tab.key}
              className={`rc-tab ${activeTab === tab.key ? 'rc-tab--active' : ''}`}
              onClick={() => setActiveTab(tab.key)}
            >
              <Icon size={16} />
              {tab.label}
            </button>
          );
        })}
      </div>

      {loading ? (
        <div className="rc-loading">
          <div className="rc-loading__spinner" />
          <p>Loading financial data...</p>
        </div>
      ) : (
        <>
          {/* Overview Tab */}
          {activeTab === 'overview' && summary && (
            <div className="rc-overview">
              <div className="rc-kpi-row">
                <div className="rc-kpi rc-kpi--revenue">
                  <TrendingUp size={20} />
                  <div className="rc-kpi__content">
                    <span className="rc-kpi__value">{formatCurrency((summary.totalRevenue as number) ?? 0)}</span>
                    <span className="rc-kpi__label">Total Revenue</span>
                  </div>
                </div>
                <div className="rc-kpi rc-kpi--payments">
                  <CheckCircle size={20} />
                  <div className="rc-kpi__content">
                    <span className="rc-kpi__value">{formatCurrency((summary.totalPayments as number) ?? 0)}</span>
                    <span className="rc-kpi__label">Payments Collected</span>
                  </div>
                </div>
                <div className="rc-kpi rc-kpi--refunds">
                  <TrendingDown size={20} />
                  <div className="rc-kpi__content">
                    <span className="rc-kpi__value">{formatCurrency((summary.totalRefunds as number) ?? 0)}</span>
                    <span className="rc-kpi__label">Refunds</span>
                  </div>
                </div>
                <div className="rc-kpi rc-kpi--outstanding">
                  <AlertTriangle size={20} />
                  <div className="rc-kpi__content">
                    <span className="rc-kpi__value">{formatCurrency((summary.outstandingMinor as number) ?? 0)}</span>
                    <span className="rc-kpi__label">Outstanding</span>
                  </div>
                </div>
              </div>

              <div className="rc-stats-row">
                <div className="rc-stat-card">
                  <span className="rc-stat-card__value">{(summary.invoiceCount as number) ?? 0}</span>
                  <span className="rc-stat-card__label">Invoices</span>
                </div>
                <div className="rc-stat-card">
                  <span className="rc-stat-card__value">{(summary.paidInvoiceCount as number) ?? 0}</span>
                  <span className="rc-stat-card__label">Paid</span>
                </div>
                <div className="rc-stat-card">
                  <span className="rc-stat-card__value">{formatCurrency((summary.totalTax as number) ?? 0)}</span>
                  <span className="rc-stat-card__label">Tax Collected</span>
                </div>
                <div className="rc-stat-card">
                  <span className="rc-stat-card__value">{formatCurrency((summary.totalAdjustments as number) ?? 0)}</span>
                  <span className="rc-stat-card__label">Net Adjustments</span>
                </div>
              </div>

              {/* Revenue by Source */}
              {bySource.length > 0 && (
                <div className="rc-section">
                  <h3>Revenue by Source</h3>
                  <div className="rc-source-table">
                    <div className="rc-table-header">
                      <span>Source</span>
                      <span>Charges</span>
                      <span>Amount</span>
                    </div>
                    {bySource.map((src, i) => (
                      <div key={i} className="rc-table-row">
                        <span className="rc-source-name">{(src.source as string) ?? 'Unknown'}</span>
                        <span>{(src.count as number) ?? 0}</span>
                        <span className="rc-amount">{formatCurrency((src.totalMinor as number) ?? 0)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Expense Summary */}
              {expenseSummary && (
                <div className="rc-section">
                  <h3>Expense Summary</h3>
                  <div className="rc-stats-row">
                    <div className="rc-stat-card">
                      <span className="rc-stat-card__value">{formatCurrency((expenseSummary.totalExpenses as number) ?? 0)}</span>
                      <span className="rc-stat-card__label">Total Expenses</span>
                    </div>
                    <div className="rc-stat-card">
                      <span className="rc-stat-card__value">{formatCurrency((expenseSummary.paidExpenses as number) ?? 0)}</span>
                      <span className="rc-stat-card__label">Paid</span>
                    </div>
                    <div className="rc-stat-card">
                      <span className="rc-stat-card__value">{formatCurrency((expenseSummary.pendingExpenses as number) ?? 0)}</span>
                      <span className="rc-stat-card__label">Pending</span>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Trends Tab */}
          {activeTab === 'trends' && (
            <div className="rc-trends">
              <div className="rc-section">
                <h3>Daily Revenue vs Payments</h3>
                {dailyTrend.length > 0 ? (
                  <div className="rc-chart-placeholder">
                    <div className="rc-chart-bars">
                      {dailyTrend.slice(-14).map((day, i) => {
                        const revenue = (day.revenue as number) ?? 0;
                        const payments = (day.payments as number) ?? 0;
                        const maxVal = Math.max(revenue, payments, 1);
                        return (
                          <div key={i} className="rc-chart-day">
                            <div className="rc-chart-bars__pair">
                              <div
                                className="rc-chart-bar rc-chart-bar--revenue"
                                style={{ height: `${(revenue / maxVal) * 120}px` }}
                                title={`Revenue: ${formatCurrency(revenue)}`}
                              />
                              <div
                                className="rc-chart-bar rc-chart-bar--payments"
                                style={{ height: `${(payments / maxVal) * 120}px` }}
                                title={`Payments: ${formatCurrency(payments)}`}
                              />
                            </div>
                            <span className="rc-chart-date">{(day.date as string)?.slice(5)}</span>
                          </div>
                        );
                      })}
                    </div>
                    <div className="rc-chart-legend">
                      <span className="rc-legend-item"><span className="rc-dot rc-dot--revenue" /> Revenue</span>
                      <span className="rc-legend-item"><span className="rc-dot rc-dot--payments" /> Payments</span>
                    </div>
                  </div>
                ) : (
                  <EmptyState title="No trend data" body="Select a date range to view daily trends." />
                )}
              </div>
            </div>
          )}

          {/* Aging Tab */}
          {activeTab === 'aging' && (
            <div className="rc-aging">
              <div className="rc-section">
                <h3>Patient Account Aging</h3>
                {agingData.length > 0 ? (
                  <div className="rc-table-scroll">
                    <div className="rc-table-header">
                      <span>Patient</span>
                      <span>Current</span>
                      <span>1–30 days</span>
                      <span>31–60 days</span>
                      <span>61–90 days</span>
                      <span>90+ days</span>
                      <span>Total</span>
                    </div>
                    {agingData.map((row, i) => (
                      <div key={i} className="rc-table-row">
                        <span className="rc-source-name">{(row.patientName as string) ?? 'Unknown'}</span>
                        <span>{formatCurrency((row.current as number) ?? 0)}</span>
                        <span>{formatCurrency((row.days30 as number) ?? 0)}</span>
                        <span>{formatCurrency((row.days60 as number) ?? 0)}</span>
                        <span className={((row.days90 as number) ?? 0) > 0 ? 'rc-overdue' : ''}>
                          {formatCurrency((row.days90 as number) ?? 0)}
                        </span>
                        <span className={((row.over90 as number) ?? 0) > 0 ? 'rc-overdue' : ''}>
                          {formatCurrency((row.over90 as number) ?? 0)}
                        </span>
                        <span className="rc-amount">{formatCurrency((row.totalOutstanding as number) ?? 0)}</span>
                      </div>
                    ))}
                  </div>
                ) : (
                  <EmptyState title="No outstanding balances" body="All patient accounts are current." />
                )}
              </div>
            </div>
          )}

          {/* Adjustments Tab */}
          {activeTab === 'adjustments' && (
            <div className="rc-adjustments">
              <EmptyState
                title="Billing Adjustments"
                body="Credit and debit adjustments are managed from individual invoice detail pages. Navigate to a specific invoice to view, request, or manage adjustments."
              />
            </div>
          )}

          {/* Receipts Tab */}
          {activeTab === 'receipts' && (
            <div className="rc-receipts">
              <EmptyState
                title="Payment Receipts"
                body="Receipts are automatically generated when payments are captured. View and print receipts from individual payment records in the billing module."
              />
            </div>
          )}
        </>
      )}
    </div>
  );
}
