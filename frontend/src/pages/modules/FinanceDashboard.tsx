import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import { useFetch } from '../../hooks/useFetch';
import { useTenant } from '../../context/TenantContext';
import { dashboardApi } from '../../api/dashboard';
import { Landmark, WalletCards, DollarSign, ChartNoAxesCombined, Receipt, CalendarClock, ShieldCheck, TrendingUp, AlertCircle } from 'lucide-react';
import '../modules/module-dashboards.css';

function getFinanceActions(access: ReturnType<typeof useAccess>) {
  const base = [
    { to: '/finance/billing', icon: WalletCards, label: 'Billing' },
    { to: '/finance/revenue', icon: DollarSign, label: 'Revenue Cycle' },
  ];
  if (access.hasAnyRole('superadmin', 'org_admin', 'hospital_admin', 'org_finance')) {
    return [
      ...base,
      { to: '/finance/budgets', icon: ChartNoAxesCombined, label: 'Budgets' },
      { to: '/finance/expenses', icon: Receipt, label: 'Expenses' },
      { to: '/finance/periods', icon: CalendarClock, label: 'Financial Periods' },
      { to: '/admin/audit', icon: ShieldCheck, label: 'Audit Trail' },
    ];
  }
  return base;
}

function formatCurrency(minor: number) {
  return `NPR ${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}

export function FinanceDashboard() {
  const { t } = useI18n();
  const access = useAccess();
  const { selectedFacilityId } = useTenant();
  const actions = getFinanceActions(access);
  const metrics = useFetch(() => dashboardApi.metrics(selectedFacilityId), [selectedFacilityId]);
  const m = metrics.data;

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Landmark size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.finance')}</h1>
          <p className="module-dash__subtitle">Billing, revenue, and financial operations</p>
        </div>
      </div>

      {m && (
        <div className="module-dash__kpis">
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--green"><TrendingUp size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{formatCurrency(m.revenueToday)}</span>
              <span className="kpi-card__label">Revenue Today</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--blue"><DollarSign size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{formatCurrency(m.revenueThisMonth)}</span>
              <span className="kpi-card__label">Revenue This Month</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--amber"><AlertCircle size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{formatCurrency(m.outstandingAmount)}</span>
              <span className="kpi-card__label">Outstanding</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--blue"><WalletCards size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.invoicesIssuedToday}</span>
              <span className="kpi-card__label">Invoices Today</span>
            </div>
          </div>
        </div>
      )}

      <div className="module-dash__grid">
        {actions.map((a) => (
          <Link key={a.to} to={a.to} className="module-dash__card">
            <a.icon size={20} strokeWidth={1.75} />
            <span>{a.label}</span>
          </Link>
        ))}
      </div>
    </div>
  );
}
