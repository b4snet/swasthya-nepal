import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import { Landmark, WalletCards, DollarSign, ChartNoAxesCombined, Receipt, CalendarClock, ShieldCheck } from 'lucide-react';
import './module-dashboards.css';

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

export function FinanceDashboard() {
  const { t } = useI18n();
  const access = useAccess();
  const actions = getFinanceActions(access);

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Landmark size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.finance')}</h1>
          <p className="module-dash__subtitle">Billing, revenue, and financial operations</p>
        </div>
      </div>
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
