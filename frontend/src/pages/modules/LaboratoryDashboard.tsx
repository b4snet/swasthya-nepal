import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import { FlaskConical, ClipboardList, FileText, CheckCircle, AlertTriangle } from 'lucide-react';
import './module-dashboards.css';

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
  const actions = getLabActions(access);

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <FlaskConical size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.laboratory')}</h1>
          <p className="module-dash__subtitle">Lab orders, sample processing, and result reporting</p>
        </div>
      </div>
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
