import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useAccess } from '../../auth/useAccess';
import { useFetch } from '../../hooks/useFetch';
import { useTenant } from '../../context/TenantContext';
import { dashboardApi } from '../../api/dashboard';
import { NeedsAttention } from '../../components/NeedsAttention';
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
  Activity,
} from 'lucide-react';
import '../modules/module-dashboards.css';

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
  const { selectedFacilityId } = useTenant();
  const actions = getHospitalActions(access);
  const metrics = useFetch(() => dashboardApi.metrics(selectedFacilityId), [selectedFacilityId]);
  const m = metrics.data;

  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <Building2 size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.hospital')}</h1>
          <p className="module-dash__subtitle">Facility operations and patient flow</p>
        </div>
      </div>

      {m && (
        <div className="module-dash__kpis">
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--blue"><Users size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.totalPatients.toLocaleString()}</span>
              <span className="kpi-card__label">Total Patients</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--green"><CalendarDays size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.appointmentsToday}</span>
              <span className="kpi-card__label">Appointments Today</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--blue"><Bed size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.occupiedBeds}/{m.totalBeds}</span>
              <span className="kpi-card__label">Bed Occupancy</span>
            </div>
          </div>
          <div className="kpi-card">
            <div className="kpi-card__icon kpi-card__icon--amber"><Activity size={18} /></div>
            <div className="kpi-card__content">
              <span className="kpi-card__value">{m.erWaiting}</span>
              <span className="kpi-card__label">ER Waiting</span>
            </div>
          </div>
        </div>
      )}

      {/* Needs Attention */}
      {m && (
        <div style={{ marginBottom: 'var(--space-6)' }}>
          <NeedsAttention metrics={m} />
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
