import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import { Pill, FileText, Boxes, ClipboardCheck } from 'lucide-react';
import './module-dashboards.css';

function getPharmacyActions(access: ReturnType<typeof useAccess>) {
  const base = [
    { to: '/pharmacy/prescriptions', icon: FileText, label: 'Prescriptions' },
    { to: '/pharmacy/dispensing', icon: Pill, label: 'Dispensing' },
    { to: '/pharmacy/inventory', icon: Boxes, label: 'Inventory' },
  ];

  if (access.hasAnyRole('superadmin', 'org_admin', 'hospital_admin', 'pharmacist')) {
    return [
      ...base,
      { to: '/pharmacy/prescriptions', icon: ClipboardCheck, label: 'Verification' },
    ];
  }

  return base;
}

export function PharmacyDashboard() {
  const { t } = useI18n();
  const access = useAccess();
  const actions = getPharmacyActions(access);

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Pill size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.pharmacy')}</h1>
          <p className="module-dash__subtitle">Prescriptions, dispensing, and medication inventory</p>
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
