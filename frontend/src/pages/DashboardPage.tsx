import { useState, useEffect, useCallback } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useAuth } from '../auth/AuthProvider';
import { appointmentsApi, realtimeApi } from '../api/endpoints';
import {
  Calendar,
  Users,
  Activity,
  Clock,
  CheckCircle,
  AlertTriangle,
  ArrowRight,
  Stethoscope,
  Pill,
  FileText,
  DollarSign,
  TrendingUp,
} from 'lucide-react';
import './dashboard-premium.css';

interface Appointment {
  id: string;
  patientId: string;
  patient?: { fullName: string; mrn: string; id: string };
  provider?: { fullName: string };
  startsAt: string;
  status: string;
}

interface QueueEntry {
  appointmentId: string;
  patient?: { fullName: string; mrn: string; id: string };
  tokenNo: string;
  status: string;
  encounterId?: string;
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function greeting(): string {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

export function DashboardPage() {
  const { selectedFacilityId, hasRole } = useTenant();
  const { user } = useAuth();
  const navigate = useNavigate();
  const fac = selectedFacilityId;

  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [queue, setQueue] = useState<QueueEntry[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(true);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [apptRes, queueRes, unreadRes] = await Promise.allSettled([
        appointmentsApi.list({ date: today(), facilityId: fac }),
        appointmentsApi.queue({ date: today(), facilityId: fac }),
        realtimeApi.unreadCount(fac ?? undefined),
      ]);

      if (apptRes.status === 'fulfilled') setAppointments(apptRes.value as unknown as Appointment[]);
      if (queueRes.status === 'fulfilled') setQueue(queueRes.value as unknown as QueueEntry[]);
      if (unreadRes.status === 'fulfilled') setUnreadCount((unreadRes.value as unknown as { count: number }).count ?? 0);
    } finally {
      setLoading(false);
    }
  }, [fac]);

  useEffect(() => {
    fetchData();
    const interval = setInterval(fetchData, 60000);
    return () => clearInterval(interval);
  }, [fetchData]);

  const completedToday = appointments.filter((a) => a.status === 'completed').length;
  const checkedIn = queue.filter((q) => q.status === 'checked_in').length;
  const inConsultation = queue.filter((q) => q.status === 'in_consultation').length;
  const pendingQueue = checkedIn + inConsultation;

  const kpis = [
    {
      label: 'Appointments',
      value: appointments.length,
      icon: Calendar,
      color: '#2563eb',
      bg: '#eff6ff',
      link: '/appointments',
    },
    {
      label: 'In Queue',
      value: pendingQueue,
      icon: Clock,
      color: '#d97706',
      bg: '#fffbeb',
      link: '/queue',
    },
    {
      label: 'Completed',
      value: completedToday,
      icon: CheckCircle,
      color: '#059669',
      bg: '#ecfdf5',
    },
    {
      label: 'Notifications',
      value: unreadCount,
      icon: AlertTriangle,
      color: unreadCount > 0 ? '#dc2626' : '#64748b',
      bg: unreadCount > 0 ? '#fef2f2' : '#f8fafc',
      link: '/operations',
    },
  ];

  return (
    <div className="dp-page">
      {/* Welcome header */}
      <header className="dp-header">
        <div className="dp-header__greeting">
          <h1>{greeting()}, {user?.email?.split('@')[0] ?? 'User'}</h1>
          <p className="dp-header__date">
            {new Date().toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
          </p>
        </div>
        <div className="dp-header__actions">
          <Link to="/patients/new" className="dp-action-btn dp-action-btn--primary">
            <Users size={16} />
            Register Patient
          </Link>
          <Link to="/appointments" className="dp-action-btn dp-action-btn--secondary">
            <Calendar size={16} />
            Book Appointment
          </Link>
        </div>
      </header>

      {/* KPI Cards */}
      <div className="dp-kpi-grid">
        {kpis.map((kpi) => {
          const Icon = kpi.icon;
          const content = (
            <div className="dp-kpi" style={{ '--kpi-color': kpi.color, '--kpi-bg': kpi.bg } as React.CSSProperties}>
              <div className="dp-kpi__icon">
                <Icon size={22} />
              </div>
              <div className="dp-kpi__content">
                <span className="dp-kpi__value">{loading ? '—' : kpi.value}</span>
                <span className="dp-kpi__label">{kpi.label}</span>
              </div>
            </div>
          );

          return kpi.link ? (
            <Link key={kpi.label} to={kpi.link} className="dp-kpi-link">
              {content}
            </Link>
          ) : (
            <div key={kpi.label}>{content}</div>
          );
        })}
      </div>

      {/* Quick actions */}
      <div className="dp-quick-actions">
        {[
          { to: '/encounters', label: 'Consultations', icon: Stethoscope },
          { to: '/pharmacy', label: 'Pharmacy', icon: Pill },
          { to: '/documents', label: 'Documents', icon: FileText },
          { to: '/revenue', label: 'Revenue', icon: DollarSign },
          { to: '/analytics', label: 'Analytics', icon: TrendingUp },
        ].map((action) => {
          const Icon = action.icon;
          return (
            <Link key={action.to} to={action.to} className="dp-quick-action">
              <Icon size={18} />
              <span>{action.label}</span>
            </Link>
          );
        })}
      </div>

      <div className="dp-grid">
        {/* Queue snapshot */}
        <div className="dp-card dp-card--queue">
          <div className="dp-card__header">
            <h2 className="dp-card__title">
              <Activity size={18} />
              Queue Now
            </h2>
            <Link to="/queue" className="dp-card__link">
              View all <ArrowRight size={14} />
            </Link>
          </div>
          <div className="dp-card__body">
            {loading ? (
              <div className="dp-skeleton-rows">
                {[1, 2, 3].map((i) => <div key={i} className="dp-skeleton-row" />)}
              </div>
            ) : queue.length === 0 ? (
              <div className="dp-empty">
                <CheckCircle size={32} />
                <p>Queue is clear — no patients waiting</p>
              </div>
            ) : (
              <div className="dp-queue-list">
                {queue.slice(0, 6).map((entry) => (
                  <div key={entry.appointmentId} className="dp-queue-item">
                    <span className="dp-queue-item__token">#{entry.tokenNo}</span>
                    <div className="dp-queue-item__info">
                      <Link to={`/patients/${entry.patient?.id}`} className="dp-queue-item__name">
                        {entry.patient?.fullName ?? 'Unknown'}
                      </Link>
                      <span className="dp-queue-item__mrn">{entry.patient?.mrn}</span>
                    </div>
                    <span className={`dp-status dp-status--${entry.status}`}>
                      {entry.status === 'checked_in' ? 'Checked In' : entry.status === 'in_consultation' ? 'In Consultation' : entry.status}
                    </span>
                    {entry.status === 'checked_in' && hasRole('doctor') && (
                      <button
                        className="dp-queue-item__action"
                        onClick={() => navigate(`/encounters/${entry.appointmentId}`)}
                      >
                        Start
                      </button>
                    )}
                    {entry.status === 'in_consultation' && entry.encounterId && (
                      <Link to={`/encounters/${entry.encounterId}`} className="dp-queue-item__action">
                        Open
                      </Link>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Today's appointments */}
        <div className="dp-card dp-card--appointments">
          <div className="dp-card__header">
            <h2 className="dp-card__title">
              <Calendar size={18} />
              Today's Appointments
            </h2>
            <Link to="/appointments" className="dp-card__link">
              View all <ArrowRight size={14} />
            </Link>
          </div>
          <div className="dp-card__body">
            {loading ? (
              <div className="dp-skeleton-rows">
                {[1, 2, 3, 4].map((i) => <div key={i} className="dp-skeleton-row" />)}
              </div>
            ) : appointments.length === 0 ? (
              <div className="dp-empty">
                <Calendar size={32} />
                <p>No appointments scheduled for today</p>
              </div>
            ) : (
              <div className="dp-appt-list">
                {appointments.slice(0, 8).map((appt) => (
                  <div key={appt.id} className="dp-appt-item">
                    <span className="dp-appt-item__time">
                      {new Date(appt.startsAt).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}
                    </span>
                    <div className="dp-appt-item__info">
                      <Link to={`/patients/${appt.patientId}`} className="dp-appt-item__name">
                        {appt.patient?.fullName ?? 'Unknown'}
                      </Link>
                      <span className="dp-appt-item__provider">
                        {appt.provider?.fullName ?? 'Unassigned'}
                      </span>
                    </div>
                    <span className={`dp-status dp-status--${appt.status}`}>
                      {appt.status === 'completed' ? 'Completed' : appt.status === 'booked' ? 'Scheduled' : appt.status}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
