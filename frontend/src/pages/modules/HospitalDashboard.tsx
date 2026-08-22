import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import {
  Building2,
  Bed,
  Siren,
  HeartPulse,
  Scissors,
  ClipboardList,
  Stethoscope,
  Users,
  CalendarDays,
  ListOrdered,
} from 'lucide-react';
import './module-dashboards.css';

function getHospitalActions(access: ReturnType<typeof useAccess>) {
  const core = [
    { to: '/hospital/opd', icon: Stethoscope, label: 'OPD' },
    { to: '/hospital/ipd', icon: Bed, label: 'IPD' },
    { to: '/beds', icon: Bed, label: 'Beds & Wards' },
    { to: '/emergency', icon: Siren, label: 'Emergency' },
    { to: '/icu', icon: HeartPulse, label: 'ICU' },
    { to: '/ot', icon: Scissors, label: 'Operating Theatre' },
  ];

  if (access.isClinical()) {
    return [
      { to: '/clinical/patients', icon: Users, label: 'Patients' },
      { to: '/clinical/appointments', icon: CalendarDays, label: 'Appointments' },
      { to: '/clinical/queue', icon: ListOrdered, label: 'Queue' },
      ...core,
      { to: '/nursing', icon: ClipboardList, label: 'Nursing' },
    ];
  }

  return [
    { to: '/clinical/patients', icon: Users, label: 'Patients' },
    { to: '/clinical/appointments', icon: CalendarDays, label: 'Appointments' },
    ...core,
    { to: '/nursing', icon: ClipboardList, label: 'Nursing' },
  ];
}

export function HospitalDashboard() {
  const { t } = useI18n();
  const access = useAccess();
  const actions = getHospitalActions(access);

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Building2 size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.hospital')}</h1>
          <p className="module-dash__subtitle">Facility operations and patient flow</p>
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
