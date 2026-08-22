import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import { useFetch } from '../../hooks/useFetch';
import { useTenant } from '../../context/TenantContext';
import { dashboardApi } from '../../api/dashboard';
import { FlaskConical, ClipboardList, FileText, CheckCircle, AlertTriangle, Clock } from 'lucide-react';
import '../modules/module-dashboards.css';

function getLabActions(access: ReturnType<typeof useAccess>) {
  const base = [
    { to: '/laboratory/orders', icon: ClipboardList, label: 'Orders' },
    { to: '/laboratory/reports', icon: FileText, label: 'Reports' },
  ];
  if (access.hasAnyRole('superadmin', 'org_admin', 'hospital_admin')) {
    return [
      ...base,
      { to: '/laboratory/orders', icon: CheckCircle, label: 'Verification' },
      { to: '/laboratory/orders', icon: AlertTriangle, label: 'Critical Values' },
    ];
  }
  return base;
}

export function LaboratoryDashboard() {
  const { t } = useI18n();
  const access = useAccess();
  const { selectedFacilityId } = useTenant();
  const actions = getLabActions(access);
  const metrics = useFetch(() => dashboardApi.metrics(selectedFacilityId), [selectedFacilityId]);
  const m = metrics.data;

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <FlaskConical size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.laboratory')}</h1>
          <p className="module-dash__subtitle">Lab orders, sample processing, and result reporting</p>
        </div>
      </div>

      {m && (
        <div className="module-dash__kpis">
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--amber"><Clock size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.pendingLabOrders}</span>
              <span className="kpi-card__label">Pending Orders</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--green"><CheckCircle size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.completedLabToday}</span>
              <span className="kpi-card__label">Completed Today</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--red"><AlertTriangle size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.criticalValues}</span>
              <span className="kpi-card__label">Critical Values</span>
            </div>
          </div>
        </div>
      )}

      <div className="module-dash__grid">
        {actions.map((a) => (
          <Link key={`${a.to}-${a.label}`} to={a.to} className="module-dash__card">
            <a.icon size={20} strokeWidth={1.75} />
            <span>{a.label}</span>
          </Link>
        ))}
      </div>
    </div>
  );
}
