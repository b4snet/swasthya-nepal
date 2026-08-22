import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import { useFetch } from '../../hooks/useFetch';
import { useTenant } from '../../context/TenantContext';
import { dashboardApi } from '../../api/dashboard';
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
  Activity,
  Clock,
  CheckCircle,
  AlertTriangle,
} from 'lucide-react';
import '../modules/module-dashboards.css';

function getClinicalActions(access: ReturnType<typeof useAccess>) {
  const base = [
    { to: '/clinical/patients', icon: Users, label: 'Patients' },
    { to: '/clinical/appointments', icon: CalendarDays, label: 'Appointments' },
  ];
  if (access.isClinical()) {
    return [
      ...base,
      { to: '/clinical/queue', icon: ListOrdered, label: 'Queue' },
      { to: '/clinical/encounters', icon: FileText, label: 'Encounters' },
      { to: '/clinical/referrals', icon: GitPullRequestArrow, label: 'Referrals' },
      { to: '/clinical/scheduling', icon: CalendarClock, label: 'Scheduling' },
      { to: '/clinical/telehealth', icon: Video, label: 'Telehealth' },
    ];
  }
  if (access.isPharmacy()) {
    return [...base, { to: '/pharmacy/prescriptions', icon: Pill, label: 'Prescriptions' }];
  }
  if (access.isLab()) {
    return [...base, { to: '/laboratory/orders', icon: ClipboardList, label: 'Lab Orders' }];
  }
  return [
    ...base,
    { to: '/clinical/patients/new', icon: Users, label: 'Register Patient' },
    { to: '/clinical/queue', icon: ListOrdered, label: 'Queue' },
    { to: '/clinical/referrals', icon: GitPullRequestArrow, label: 'Referrals' },
  ];
}

export function ClinicalDashboard() {
  const { t } = useI18n();
  const access = useAccess();
  const { selectedFacilityId } = useTenant();
  const actions = getClinicalActions(access);
  const metrics = useFetch(() => dashboardApi.metrics(selectedFacilityId), [selectedFacilityId]);

  const m = metrics.data;

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Stethoscope size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.clinical')}</h1>
          <p className="module-dash__subtitle">Patient care and clinical workflows</p>
        </div>
      </div>

      {/* KPI cards */}
      {m && (
        <div className="module-dash__kpis">
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--blue"><Clock size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.inQueue}</span>
              <span className="kpi-card__label">In Queue</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--green"><CheckCircle size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.completedToday}</span>
              <span className="kpi-card__label">Completed Today</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--blue"><Activity size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.encountersToday}</span>
              <span className="kpi-card__label">Encounters Today</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--amber"><AlertTriangle size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.criticalValues}</span>
              <span className="kpi-card__label">Critical Values</span>
            </div>
          </div>
        </div>
      )}

      {/* Quick actions */}
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
