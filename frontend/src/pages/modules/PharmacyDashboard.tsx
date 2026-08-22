import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import { useFetch } from '../../hooks/useFetch';
import { useTenant } from '../../context/TenantContext';
import { dashboardApi } from '../../api/dashboard';
import { Pill, FileText, Boxes, ClipboardCheck, AlertTriangle, TrendingDown } from 'lucide-react';
import '../modules/module-dashboards.css';

function getPharmacyActions(access: ReturnType<typeof useAccess>) {
  const base = [
    { to: '/pharmacy/prescriptions', icon: FileText, label: 'Prescriptions' },
    { to: '/pharmacy/dispensing', icon: Pill, label: 'Dispensing' },
    { to: '/pharmacy/inventory', icon: Boxes, label: 'Inventory' },
  ];
  if (access.hasAnyRole('superadmin', 'org_admin', 'hospital_admin', 'pharmacist')) {
    return [...base, { to: '/pharmacy/prescriptions', icon: ClipboardCheck, label: 'Verification' }];
  }
  return base;
}

export function PharmacyDashboard() {
  const { t } = useI18n();
  const access = useAccess();
  const { selectedFacilityId } = useTenant();
  const actions = getPharmacyActions(access);
  const metrics = useFetch(() => dashboardApi.metrics(selectedFacilityId), [selectedFacilityId]);
  const m = metrics.data;

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Pill size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.pharmacy')}</h1>
          <p className="module-dash__subtitle">Prescriptions, dispensing, and medication inventory</p>
        </div>
      </div>

      {m && (
        <div className="module-dash__kpis">
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--blue"><FileText size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.prescriptionsToday}</span>
              <span className="kpi-card__label">Prescriptions Today</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--green"><Pill size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.dispensingsToday}</span>
              <span className="kpi-card__label">Dispensed Today</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--amber"><AlertTriangle size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.lowStockItems}</span>
              <span className="kpi-card__label">Low Stock Items</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--red"><TrendingDown size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.expiringItems}</span>
              <span className="kpi-card__label">Expiring Soon</span>
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
