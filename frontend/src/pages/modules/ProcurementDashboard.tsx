import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { Boxes, ShoppingCart, Package, TrendingDown } from 'lucide-react';
import './module-dashboards.css';

const actions = [
  { to: '/procurement/inventory', icon: Boxes, label: 'Inventory' },
  { to: '/procurement/orders', icon: ShoppingCart, label: 'Purchase Orders' },
  { to: '/procurement/orders', icon: Package, label: 'Receiving' },
  { to: '/procurement/inventory', icon: TrendingDown, label: 'Stock Movements' },
];

export function ProcurementDashboard() {
  const { t } = useI18n();
  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Boxes size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.procurement')}</h1>
          <p className="module-dash__subtitle">Inventory management, stock, and procurement</p>
        </div>
      </div>
      <div className="module-dash__grid">
        {actions.map((a) => (
          <Link key={a.label} to={a.to} className="module-dash__card">
            <a.icon size={20} strokeWidth={1.75} />
            <span>{a.label}</span>
          </Link>
        ))}
      </div>
    </div>
  );
}
