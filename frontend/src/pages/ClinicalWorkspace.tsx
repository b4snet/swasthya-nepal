import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useFetch } from '../hooks/useFetch';
import { useTenant } from '../context/TenantContext';
import { appointmentsApi } from '../api/endpoints';
import { dashboardApi } from '../api/dashboard';
import {
  CalendarDays,
  Clock,
  Users,
  FileText,
  AlertTriangle,
  CheckCircle,
  Activity,
  Stethoscope,
  ChevronRight,
  Pill,
  FlaskConical,
  Bell,
} from 'lucide-react';
import './clinical-workspace.css';

/* ── Helpers ── */
function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

function timeAgo(iso: string) {
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

function statusColor(status: string) {
  switch (status) {
    case 'completed': return 'status--green';
    case 'in_consultation': return 'status--blue';
    case 'checked_in': return 'status--amber';
    case 'cancelled': return 'status--red';
    case 'no_show': return 'status--gray';
    default: return 'status--gray';
  }
}

function statusLabel(status: string) {
  switch (status) {
    case 'completed': return 'Completed';
    case 'in_consultation': return 'In consultation';
    case 'checked_in': return 'Checked in';
    case 'cancelled': return 'Cancelled';
    case 'no_show': return 'No show';
    case 'scheduled': return 'Scheduled';
    default: return status;
  }
}

type Appointment = {
  id: string;
  patientName: string;
  patientId: string;
  startsAt: string;
  status: string;
  type: string;
  provider: string;
  department: string;
};

export function ClinicalWorkspace() {
  const { selectedFacilityId } = useTenant();
  const [tab, setTab] = useState<'schedule' | 'queue' | 'alerts'>('schedule');

  // Fetch today's appointments
  const appointments = useFetch(
    () => appointmentsApi.list({ date: new Date().toISOString().slice(0, 10), facilityId: selectedFacilityId }),
    [selectedFacilityId],
  );

  // Fetch dashboard metrics for KPIs
  const metrics = useFetch(
    () => dashboardApi.metrics(selectedFacilityId),
    [selectedFacilityId],
  );

  // Fetch chart data for upcoming appointments
  const chartData = useFetch(
    () => dashboardApi.chartData(selectedFacilityId, 1),
    [selectedFacilityId],
  );

  const m = metrics.data;
  const appts = useMemo(() => {
    const raw = (appointments.data as any)?.data ?? (appointments.data as any) ?? [];
    return Array.isArray(raw) ? raw : [];
  }, [appointments.data]);
  const recentPatients = chartData.data?.recentPatients ?? [];
  const pendingLab = chartData.data?.pendingLabResults ?? [];

  // Separate appointments by status
  const inQueue = appts.filter((a: Appointment) => a.status === 'checked_in');
  const inConsultation = appts.filter((a: Appointment) => a.status === 'in_consultation');
  const completed = appts.filter((a: Appointment) => a.status === 'completed');

  return (
    <div className="cw">
      {/* Header */}
      <div className="cw__header">
        <div className="cw__header-left">
          <Stethoscope size={22} strokeWidth={1.5} />
          <div>
            <h1>Clinical workspace</h1>
            <p className="cw__subtitle">
              {new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}
            </p>
          </div>
        </div>
      </div>

      {/* KPI row */}
      {m && (
        <div className="cw__kpis">
          <div className="cw__kpi">
            <div className="cw__kpi-icon cw__kpi-icon--blue"><CalendarDays size={16} /></div>
            <div className="cw__kpi-data">
              <span className="cw__kpi-value">{appts.length}</span>
              <span className="cw__kpi-label">Today's appointments</span>
            </div>
          </div>
          <div className="cw__kpi">
            <div className="cw__kpi-icon cw__kpi-icon--amber"><Clock size={16} /></div>
            <div className="cw__kpi-data">
              <span className="cw__kpi-value">{inQueue.length}</span>
              <span className="cw__kpi-label">Waiting in queue</span>
            </div>
          </div>
          <div className="cw__kpi">
            <div className="cw__kpi-icon cw__kpi-icon--blue"><Activity size={16} /></div>
            <div className="cw__kpi-data">
              <span className="cw__kpi-value">{inConsultation.length}</span>
              <span className="cw__kpi-label">In consultation</span>
            </div>
          </div>
          <div className="cw__kpi">
            <div className="cw__kpi-icon cw__kpi-icon--green"><CheckCircle size={16} /></div>
            <div className="cw__kpi-data">
              <span className="cw__kpi-value">{completed.length}</span>
              <span className="cw__kpi-label">Completed</span>
            </div>
          </div>
          {m.criticalValues > 0 && (
            <div className="cw__kpi cw__kpi--alert">
              <div className="cw__kpi-icon cw__kpi-icon--red"><AlertTriangle size={16} /></div>
              <div className="cw__kpi-data">
                <span className="cw__kpi-value">{m.criticalValues}</span>
                <span className="cw__kpi-label">Critical values</span>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Tab navigation */}
      <div className="cw__tabs">
        <button
          type="button"
          className={`cw__tab ${tab === 'schedule' ? 'cw__tab--active' : ''}`}
          onClick={() => setTab('schedule')}
        >
          <CalendarDays size={15} />
          Schedule
        </button>
        <button
          type="button"
          className={`cw__tab ${tab === 'queue' ? 'cw__tab--active' : ''}`}
          onClick={() => setTab('queue')}
        >
          <Users size={15} />
          Queue
          {inQueue.length > 0 && <span className="cw__tab-badge">{inQueue.length}</span>}
        </button>
        <button
          type="button"
          className={`cw__tab ${tab === 'alerts' ? 'cw__tab--active' : ''}`}
          onClick={() => setTab('alerts')}
        >
          <Bell size={15} />
          Alerts
          {m && m.criticalValues > 0 && <span className="cw__tab-badge cw__tab-badge--red">{m.criticalValues}</span>}
        </button>
      </div>

      {/* Tab content */}
      <div className="cw__content">
        {tab === 'schedule' && (
          <div className="cw__schedule">
            {/* Today's appointments */}
            <div className="cw__section">
              <h2 className="cw__section-title">Today's appointments</h2>
              {appts.length === 0 ? (
                <div className="cw__empty">
                  <CalendarDays size={32} strokeWidth={1.25} />
                  <p className="cw__empty-title">No appointments today</p>
                  <p className="cw__empty-desc">The schedule is clear for today.</p>
                </div>
              ) : (
                <div className="cw__appt-list">
                  {appts.map((appt: Appointment) => (
                    <Link
                      key={appt.id}
                      to={`/clinical/appointments/${appt.id}`}
                      className="cw__appt"
                    >
                      <div className="cw__appt-time">{formatTime(appt.startsAt)}</div>
                      <div className="cw__appt-info">
                        <span className="cw__appt-patient">{appt.patientName}</span>
                        <span className="cw__appt-type">{appt.type} · {appt.department}</span>
                      </div>
                      <span className={`cw__appt-status ${statusColor(appt.status)}`}>
                        {statusLabel(appt.status)}
                      </span>
                      <ChevronRight size={16} className="cw__appt-chevron" />
                    </Link>
                  ))}
                </div>
              )}
            </div>

            {/* Recent patients */}
            {recentPatients.length > 0 && (
              <div className="cw__section">
                <h2 className="cw__section-title">Recent patients</h2>
                <div className="cw__patient-list">
                  {recentPatients.map((p) => (
                    <Link
                      key={p.id}
                      to={`/clinical/patients/${p.id}`}
                      className="cw__patient"
                    >
                      <div className="cw__patient-avatar">
                        {p.name.slice(0, 2).toUpperCase()}
                      </div>
                      <div className="cw__patient-info">
                        <span className="cw__patient-name">{p.name}</span>
                        <span className="cw__patient-mrn">{p.mrn}</span>
                      </div>
                      <span className="cw__patient-visit">{timeAgo(p.lastVisit)}</span>
                    </Link>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {tab === 'queue' && (
          <div className="cw__queue">
            <div className="cw__section">
              <h2 className="cw__section-title">Patients waiting</h2>
              {inQueue.length === 0 ? (
                <div className="cw__empty">
                  <Users size={32} strokeWidth={1.25} />
                  <p className="cw__empty-title">Queue is clear</p>
                  <p className="cw__empty-desc">No patients are currently waiting.</p>
                </div>
              ) : (
                <div className="cw__appt-list">
                  {inQueue.map((appt: Appointment) => (
                    <Link
                      key={appt.id}
                      to={`/clinical/appointments/${appt.id}`}
                      className="cw__appt cw__appt--urgent"
                    >
                      <div className="cw__appt-time">{formatTime(appt.startsAt)}</div>
                      <div className="cw__appt-info">
                        <span className="cw__appt-patient">{appt.patientName}</span>
                        <span className="cw__appt-type">{appt.type} · Checked in</span>
                      </div>
                      <span className="cw__appt-status status--amber">Waiting</span>
                      <ChevronRight size={16} className="cw__appt-chevron" />
                    </Link>
                  ))}
                </div>
              )}
            </div>

            {/* In consultation */}
            {inConsultation.length > 0 && (
              <div className="cw__section">
                <h2 className="cw__section-title">In consultation</h2>
                <div className="cw__appt-list">
                  {inConsultation.map((appt: Appointment) => (
                    <Link
                      key={appt.id}
                      to={`/clinical/encounters/${appt.id}`}
                      className="cw__appt"
                    >
                      <div className="cw__appt-time">{formatTime(appt.startsAt)}</div>
                      <div className="cw__appt-info">
                        <span className="cw__appt-patient">{appt.patientName}</span>
                        <span className="cw__appt-type">{appt.type}</span>
                      </div>
                      <span className="cw__appt-status status--blue">Active</span>
                      <ChevronRight size={16} className="cw__appt-chevron" />
                    </Link>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {tab === 'alerts' && (
          <div className="cw__alerts">
            {/* Critical lab values */}
            {pendingLab.length > 0 && (
              <div className="cw__section">
                <h2 className="cw__section-title">
                  <AlertTriangle size={16} className="cw__section-icon cw__section-icon--red" />
                  Pending lab results
                </h2>
                <div className="cw__appt-list">
                  {pendingLab.map((lab: any) => (
                    <div key={lab.id} className="cw__alert-item">
                      <FlaskConical size={16} className="cw__alert-icon" />
                      <div className="cw__alert-info">
                        <span className="cw__alert-title">{lab.test}</span>
                        <span className="cw__alert-meta">{lab.patientName} · {timeAgo(lab.orderedAt)}</span>
                      </div>
                      <span className={`cw__alert-status ${lab.status === 'critical' ? 'status--red' : 'status--amber'}`}>
                        {lab.status}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Low stock medications */}
            {m && m.lowStockItems > 0 && (
              <div className="cw__section">
                <h2 className="cw__section-title">
                  <Pill size={16} className="cw__section-icon cw__section-icon--amber" />
                  Low stock medications
                </h2>
                <p className="cw__section-desc">{m.lowStockItems} medication{m.lowStockItems !== 1 ? 's' : ''} below reorder level</p>
              </div>
            )}

            {/* Expiring medications */}
            {m && m.expiringItems > 0 && (
              <div className="cw__section">
                <h2 className="cw__section-title">
                  <AlertTriangle size={16} className="cw__section-icon cw__section-icon--amber" />
                  Expiring soon
                </h2>
                <p className="cw__section-desc">{m.expiringItems} stock batch{m.expiringItems !== 1 ? 'es' : ''} expiring within 3 months</p>
              </div>
            )}

            {/* No alerts */}
            {pendingLab.length === 0 && (!m || (m.criticalValues === 0 && m.lowStockItems === 0)) && (
              <div className="cw__empty">
                <CheckCircle size={32} strokeWidth={1.25} />
                <p className="cw__empty-title">No alerts</p>
                <p className="cw__empty-desc">All clear — no items require your attention.</p>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Quick actions footer */}
      <div className="cw__actions">
        <Link to="/clinical/patients" className="cw__action">
          <Users size={16} />
          Patients
        </Link>
        <Link to="/clinical/appointments" className="cw__action">
          <CalendarDays size={16} />
          Appointments
        </Link>
        <Link to="/clinical/referrals" className="cw__action">
          <FileText size={16} />
          Referrals
        </Link>
        <Link to="/laboratory/orders" className="cw__action">
          <FlaskConical size={16} />
          Lab orders
        </Link>
      </div>
    </div>
  );
}
