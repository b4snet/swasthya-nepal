import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import {
  Stethoscope,
  Users,
  CalendarDays,
  ListOrdered,
  FileText,
  GitPullRequestArrow,
  CalendarClock,
  Video,
  ClipboardList,
  Pill,
} from 'lucide-react';
import './module-dashboards.css';

/** Quick actions vary by role */
function getClinicalActions(access: ReturnType<typeof useAccess>) {
  const base = [
    { to: '/clinical/patients', icon: Users, labelKey: 'nav.patients' as const, label: 'Patients' },
    { to: '/clinical/appointments', icon: CalendarDays, labelKey: 'nav.appointments' as const, label: 'Appointments' },
  ];

  if (access.isClinical()) {
    return [
      ...base,
      { to: '/clinical/queue', icon: ListOrdered, labelKey: 'nav.queue' as const, label: 'Queue' },
      { to: '/clinical/encounters', icon: FileText, labelKey: 'nav.encounters' as const, label: 'Encounters' },
      { to: '/clinical/referrals', icon: GitPullRequestArrow, labelKey: 'nav.referrals' as const, label: 'Referrals' },
      { to: '/clinical/scheduling', icon: CalendarClock, labelKey: 'nav.physicianScheduling' as const, label: 'Scheduling' },
      { to: '/clinical/telehealth', icon: Video, labelKey: 'nav.telehealth' as const, label: 'Telehealth' },
    ];
  }

  if (access.isPharmacy()) {
    return [
      ...base,
      { to: '/pharmacy/prescriptions', icon: Pill, labelKey: 'nav.prescriptions' as const, label: 'Prescriptions' },
    ];
  }

  if (access.isLab()) {
    return [
      ...base,
      { to: '/laboratory/orders', icon: ClipboardList, labelKey: 'nav.labOrders' as const, label: 'Lab Orders' },
    ];
  }

  // Default: receptionist / admin
  return [
    ...base,
    { to: '/clinical/patients/new', icon: Users, labelKey: 'module.registerPatient' as const, label: 'Register Patient' },
    { to: '/clinical/queue', icon: ListOrdered, labelKey: 'nav.queue' as const, label: 'Queue' },
    { to: '/clinical/referrals', icon: GitPullRequestArrow, labelKey: 'nav.referrals' as const, label: 'Referrals' },
  ];
}

export function ClinicalDashboard() {
  const { t } = useI18n();
  const access = useAccess();
  const actions = getClinicalActions(access);

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Stethoscope size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.clinical')}</h1>
          <p className="module-dash__subtitle">Patient care and clinical workflows</p>
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
