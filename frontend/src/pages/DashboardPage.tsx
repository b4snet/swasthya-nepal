import { useState, useEffect, useCallback, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useAuth } from '../auth/AuthProvider';
import {
  Users, Calendar, Clock, Bed, DollarSign, Pill, TestTube,
  Image, AlertTriangle, Stethoscope, CheckCircle, FileText,
  TrendingUp, TrendingDown, Building2, Globe,
} from 'lucide-react';
import {
  AreaChart, Area, BarChart, Bar,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  PieChart, Pie, Cell,
} from 'recharts';
import { dashboardApi, type DashboardMetrics, type ChartData } from '../api/dashboard';
import './dashboard-premium.css';

/* ── Constants ── */
const BLUE = '#2e90fa';
const GREEN = '#12b76a';
const RED = '#f04438';
const AMBER = '#f79009';
const GRAY = '#98a2b3';
const CHART_PALETTE = [BLUE, GREEN, AMBER, RED, GRAY, '#8b5cf6', '#06b6d4'];

/* ── Helpers ── */
function dateRange(days: number) {
  const end = new Date();
  const start = new Date();
  start.setDate(start.getDate() - days);
  return { start: start.toISOString().slice(0, 10), end: end.toISOString().slice(0, 10) };
}

function greeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

function currentTime() {
  return new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

function formatDate(d: Date) {
  return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

function formatCurrency(minor: number) {
  return `NPR ${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}

function timeAgo(dateStr: string) {
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

function formatTime(dateStr: string) {
  return new Date(dateStr).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}



const EMPTY_CHARTS: ChartData = {
  patientVolume: [],
  appointmentVolume: [],
  revenueTrend: [],
  bedOccupancy: { occupied: 0, available: 0, cleaning: 0, total: 0 },
  appointmentsByStatus: [],
  labWorkload: [],
  departmentActivity: [],
  recentPatients: [],
  upcomingAppointments: [],
  recentAdmissions: [],
  pendingLabResults: [],
  lowStockMedications: [],
};

/* ── Page ── */
export function DashboardPage() {
  const { selectedFacilityId, selectedFacilityName } = useTenant();
  const { user } = useAuth();
  const fac = selectedFacilityId;
  const isPlatform = !fac; // Show platform overview when no facility is selected

  const [metrics, setMetrics] = useState<DashboardMetrics | null>(null);
  const [charts, setCharts] = useState<ChartData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [dateStart, setDateStart] = useState(() => dateRange(30).start);
  const [dateEnd, setDateEnd] = useState(() => dateRange(30).end);
  const [clock, setClock] = useState(currentTime());
  const prevMetrics = useRef<DashboardMetrics | null>(null);

  // Tick clock every 30s
  useEffect(() => {
    const id = setInterval(() => setClock(currentTime()), 30000);
    return () => clearInterval(id);
  }, []);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [mRes, cRes] = await Promise.allSettled([
        dashboardApi.metrics(fac),
        dashboardApi.chartData(fac, 30),
      ]);
      if (mRes.status === 'fulfilled') {
        prevMetrics.current = mRes.value as DashboardMetrics;
        setMetrics(mRes.value as DashboardMetrics);
      } else {
        setError('Failed to load dashboard metrics');
      }
      if (cRes.status === 'fulfilled') setCharts(cRes.value as ChartData);
    } catch {
      setError('Failed to load dashboard data');
    } finally {
      setLoading(false);
    }
  }, [fac]);

  useEffect(() => {
    fetchData();
    const id = setInterval(fetchData, 60000);
    return () => clearInterval(id);
  }, [fetchData]);

  /* ── Loading state ── */
  if (loading && !metrics) {
    return (
      <div className="dashboard">
        <div className="dash-pulse dash-animate">
          <div className="dash-pulse__left">
            <div className="dash-skeleton" style={{ width: 280, height: 32, borderRadius: 8 }} />
            <div className="dash-skeleton" style={{ width: 180, height: 16, borderRadius: 6, marginTop: 8 }} />
          </div>
        </div>
        <div className="dash-hero-kpis">
          {Array.from({ length: 4 }).map((_, i) => <div key={i} className="dash-skeleton dash-skeleton--kpi" />)}
        </div>
        <div className="dash-charts">
          <div className="dash-skeleton dash-skeleton--chart" />
          <div className="dash-skeleton dash-skeleton--chart" />
        </div>
      </div>
    );
  }

  /* ── Error state ── */
  if (error && !metrics) {
    return (
      <div className="dashboard">
        <div className="dash-pulse dash-animate">
          <div className="dash-pulse__left">
            <h1 className="dash-pulse__greeting">{greeting()}, {user?.email?.split('@')[0] || 'User'}</h1>
            <div className="dash-pulse__meta">
              <span>{formatDate(new Date())}</span>
              <span style={{ color: 'var(--red-500)' }}>{error}</span>
            </div>
          </div>
          <div className="dash-pulse__actions">
            <button className="btn btn--secondary btn--sm" onClick={fetchData}>Retry</button>
          </div>
        </div>
      </div>
    );
  }

  /* ── No data state ── */
  if (!metrics) {
    return (
      <div className="dashboard">
        <div className="dash-pulse dash-animate">
          <div className="dash-pulse__left">
            <h1 className="dash-pulse__greeting">{greeting()}, {user?.email?.split('@')[0] || 'User'}</h1>
            <div className="dash-pulse__meta">
              <span>{formatDate(new Date())}</span>
              <span style={{ color: 'var(--text-tertiary)' }}>No data available — select a facility</span>
            </div>
          </div>
          <div className="dash-pulse__actions">
            <Link to="/patients/new" className="btn btn--primary btn--sm"><Users size={14} /> New Patient</Link>
            <Link to="/appointments" className="btn btn--secondary btn--sm"><Calendar size={14} /> Appointments</Link>
          </div>
        </div>
      </div>
    );
  }

  const m = metrics;
  const c = charts ?? EMPTY_CHARTS;
  const platformStats = isPlatform ? { totalOrganizations: (m as any).totalOrganizations ?? 0, totalFacilities: (m as any).totalFacilities ?? 0 } : null;

  const userName = user?.email?.split('@')[0] || 'User';

  return (
    <div className="dashboard">
      {/* ═══ PULSE HEADER ═══ */}
      <div className="dash-pulse dash-animate">
        <div className="dash-pulse__left">
          <h1 className="dash-pulse__greeting">{greeting()}, {userName}</h1>
          <div className="dash-pulse__meta">
            <span>{isPlatform ? 'Platform overview' : selectedFacilityName || formatDate(new Date())}</span>
            <span className="dash-pulse__live">
              <span className="dash-pulse__dot" />
              {clock}
            </span>
          </div>
        </div>
        <div className="dash-pulse__actions">
          <Link to="/patients/new" className="btn btn--primary btn--sm"><Users size={14} /> New Patient</Link>
          <Link to="/appointments" className="btn btn--secondary btn--sm"><Calendar size={14} /> Appointments</Link>
        </div>
      </div>

      {/* ═══ ALERTS ═══ */}
      {(m.criticalValues > 0 || m.lowStockItems > 0 || m.erWaiting > 0) && (
        <div className="dash-alerts dash-animate">
          {m.criticalValues > 0 && (
            <div className="dash-alert dash-alert--danger">
              <AlertTriangle size={16} className="dash-alert__icon" />
              <span className="dash-alert__text">
                <strong>{m.criticalValues} critical lab value{m.criticalValues > 1 ? 's' : ''}</strong>
                <span className="dash-alert__sub"> — pending acknowledgment</span>
              </span>
            </div>
          )}
          {m.lowStockItems > 0 && (
            <div className="dash-alert dash-alert--warning">
              <Pill size={16} className="dash-alert__icon" />
              <span className="dash-alert__text">
                <strong>{m.lowStockItems} medication{m.lowStockItems > 1 ? 's' : ''}</strong>
                <span className="dash-alert__sub"> below reorder level</span>
              </span>
            </div>
          )}
          {m.erWaiting > 0 && (
            <div className="dash-alert dash-alert--warning">
              <Clock size={16} className="dash-alert__icon" />
              <span className="dash-alert__text">
                <strong>{m.erWaiting} patient{m.erWaiting > 1 ? 's' : ''}</strong>
                <span className="dash-alert__sub"> waiting in emergency</span>
              </span>
            </div>
          )}
        </div>
      )}

      {/* ═══ FILTER ═══ */}
      <div className="dash-filter dash-animate">
        <span className="dash-filter__label">From</span>
        <input type="date" className="dash-filter__input" value={dateStart}
          onChange={(e) => setDateStart(e.target.value)} />
        <span className="dash-filter__label">To</span>
        <input type="date" className="dash-filter__input" value={dateEnd}
          onChange={(e) => setDateEnd(e.target.value)} />
      </div>

      {/* ═══ PLATFORM OVERVIEW (superadmin) ═══ */}
      {isPlatform && platformStats && (
        <div className="dash-section dash-animate">
          <div className="dash-section__head">
            <h2 className="dash-section__title">Platform overview</h2>
            <p className="dash-section__sub">All organizations and facilities</p>
          </div>
          <div className="dash-hero-kpis">
            <HeroKpi label="Organizations" value={platformStats.totalOrganizations} icon={Building2} color="blue" />
            <HeroKpi label="Facilities" value={platformStats.totalFacilities} icon={Globe} color="green" />
            <HeroKpi label="Total patients" value={m.totalPatients} icon={Users} color="blue"
              trend={m.newPatientsToday > 0 ? { text: `+${m.newPatientsToday} today`, dir: 'up' } : undefined} />
            <HeroKpi label="Revenue this month" value={formatCurrency(m.revenueThisMonth)} icon={DollarSign} color="green"
              trend={m.revenueToday > 0 ? { text: `${formatCurrency(m.revenueToday)} today`, dir: 'up' } : undefined} />
          </div>
        </div>
      )}

      {/* ═══ HERO KPIs ═══ */}
      {!isPlatform && (
        <div className="dash-section dash-animate">
          <div className="dash-section__head">
            <h2 className="dash-section__title">Today</h2>
            <p className="dash-section__sub">{formatDate(new Date())}</p>
          </div>
          <div className="dash-hero-kpis">
            <HeroKpi label="Appointments" value={m.appointmentsToday} icon={Calendar} color="blue"
              trend={m.completedToday > 0 ? { text: `${m.completedToday} done`, dir: 'up' } : undefined} />
            <HeroKpi label="In queue" value={m.inQueue + m.inConsultation} icon={Clock}
              color={m.inQueue > 10 ? 'amber' : 'blue'} />
            <HeroKpi label="Encounters" value={m.encountersToday} icon={Stethoscope} color="green"
              trend={{ text: `${m.encountersThisWeek} this week`, dir: 'neutral' }} />
            <HeroKpi label="Revenue" value={formatCurrency(m.revenueToday)} icon={DollarSign} color="green"
              trend={m.outstandingAmount > 0 ? { text: `${formatCurrency(m.outstandingAmount)} outstanding`, dir: 'down' } : undefined} />
          </div>
        </div>
      )}

      {/* ═══ QUEUE SNAPSHOT ═══ */}
      {!isPlatform && (m.inQueue > 0 || m.inConsultation > 0 || m.checkInsToday > 0) && (
        <div className="dash-section dash-animate">
          <div className="dash-section__head">
            <h2 className="dash-section__title">Patient pipeline</h2>
          </div>
          <div className="dash-queue">
            <QueueStat label="Checked in" value={m.checkInsToday} sub="arrived today" color="green" />
            <QueueStat label="Waiting" value={m.inQueue} sub={`avg ${m.avgWaitMinutes}min wait`}
              color={m.inQueue > 10 ? 'amber' : 'blue'} />
            <QueueStat label="In consultation" value={m.inConsultation} sub="with provider" color="blue" />
          </div>
        </div>
      )}

      {/* ═══ CHARTS ═══ */}
      <div className="dash-charts dash-animate">
        {/* Patient Volume */}
        <div className="dash-chart">
          <div className="dash-chart__head">
            <div>
              <p className="dash-chart__title">Patient registrations</p>
              <p className="dash-chart__sub">Last 30 days</p>
            </div>
          </div>
          <div className="dash-chart__body">
            {c.patientVolume.length > 0 ? (
              <ResponsiveContainer width="100%" height={220}>
                <AreaChart data={c.patientVolume}>
                  <defs>
                    <linearGradient id="grad-blue" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor={BLUE} stopOpacity={0.12} />
                      <stop offset="95%" stopColor={BLUE} stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" />
                  <XAxis dataKey="date" tick={{ fontSize: 11, fill: 'var(--text-tertiary)' }} tickFormatter={(v) => v.slice(5)} />
                  <YAxis tick={{ fontSize: 11, fill: 'var(--text-tertiary)' }} width={32} />
                  <Tooltip contentStyle={{ fontSize: 12, border: '1px solid var(--border-subtle)', borderRadius: 8, background: 'var(--surface-card)' }} formatter={(v) => [Number(v), 'Patients']} labelFormatter={(l) => `Date: ${l}`} />
                  <Area type="monotone" dataKey="value" stroke={BLUE} strokeWidth={2} fill="url(#grad-blue)" />
                </AreaChart>
              </ResponsiveContainer>
            ) : (
              <div className="dash-empty" style={{ height: 220 }}>
                <p className="dash-empty__title">No patient registration data</p>
                <p className="dash-empty__sub">Data will appear as patients are registered</p>
              </div>
            )}
          </div>
        </div>

        {/* Revenue */}
        <div className="dash-chart">
          <div className="dash-chart__head">
            <div>
              <p className="dash-chart__title">Revenue trend</p>
              <p className="dash-chart__sub">Paid invoices</p>
            </div>
          </div>
          <div className="dash-chart__body">
            {c.revenueTrend.length > 0 ? (
              <ResponsiveContainer width="100%" height={220}>
                <BarChart data={c.revenueTrend}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" />
                  <XAxis dataKey="date" tick={{ fontSize: 11, fill: 'var(--text-tertiary)' }} tickFormatter={(v) => v.slice(5)} />
                  <YAxis tick={{ fontSize: 11, fill: 'var(--text-tertiary)' }} width={48} tickFormatter={(v) => `${(v / 100).toFixed(0)}K`} />
                  <Tooltip contentStyle={{ fontSize: 12, border: '1px solid var(--border-subtle)', borderRadius: 8, background: 'var(--surface-card)' }} formatter={(v) => [formatCurrency(Number(v)), 'Revenue']} />
                  <Bar dataKey="value" fill={BLUE} radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            ) : (
              <div className="dash-empty" style={{ height: 220 }}>
                <p className="dash-empty__title">No revenue data</p>
                <p className="dash-empty__sub">Revenue will appear as invoices are paid</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* ═══ RECENT + APPOINTMENTS ═══ */}
      <div className="dash-duo dash-animate">
        {/* Recent Patients */}
        <div className="dash-card">
          <div className="dash-card__head">
            <p className="dash-card__title">Recent patients</p>
            {c.recentPatients.length > 0 && <span className="dash-card__badge">{c.recentPatients.length}</span>}
          </div>
          <div className="dash-card__body">
            {c.recentPatients.length > 0 ? (
              <div className="dash-feed">
                {c.recentPatients.slice(0, 7).map((p) => (
                  <Link key={p.id} to={`/patients/${p.id}`} className="dash-feed__item">
                    <span className="dash-feed__dot dash-feed__dot--blue" />
                    <span className="dash-feed__name">
                      {p.name}
                      <span className="dash-feed__mrn">{p.mrn}</span>
                    </span>
                    <span className="dash-feed__time">{timeAgo(p.lastVisit)}</span>
                  </Link>
                ))}
              </div>
            ) : (
              <div className="dash-empty"><p className="dash-empty__title">No recent patients</p></div>
            )}
          </div>
        </div>

        {/* Upcoming Appointments */}
        <div className="dash-card">
          <div className="dash-card__head">
            <p className="dash-card__title">Upcoming appointments</p>
            {c.upcomingAppointments.length > 0 && <span className="dash-card__badge">{c.upcomingAppointments.length}</span>}
          </div>
          <div className="dash-card__body">
            {c.upcomingAppointments.length > 0 ? (
              c.upcomingAppointments.slice(0, 7).map((a, i) => (
                <div key={i} className="dash-appt">
                  <span className="dash-appt__time">{formatTime(a.time)}</span>
                  <div className="dash-appt__info">
                    <div className="dash-appt__patient">{a.patientName}</div>
                    <div className="dash-appt__detail">{a.type} · {a.provider}</div>
                  </div>
                  <StatusBadge status={a.status} />
                </div>
              ))
            ) : (
              <div className="dash-empty"><p className="dash-empty__title">No upcoming appointments</p></div>
            )}
          </div>
        </div>
      </div>

      {/* ═══ IPD + BEDS ═══ */}
      {m.totalBeds > 0 && (
        <div className="dash-section dash-animate">
          <div className="dash-section__head">
            <h2 className="dash-section__title">Inpatient department</h2>
            <p className="dash-section__sub">Bed occupancy</p>
          </div>
          <div className="dash-hero-kpis" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
            <HeroKpi label="Occupied" value={m.occupiedBeds} icon={Bed} color="blue"
              trend={{ text: `${m.totalBeds > 0 ? Math.round((m.occupiedBeds / m.totalBeds) * 100) : 0}%`, dir: 'neutral' }} />
            <HeroKpi label="Available" value={m.availableBeds} icon={Bed} color="green" />
            <HeroKpi label="Admissions / Discharges" value={`${m.admissionsToday} / ${m.dischargesToday}`} icon={TrendingUp} color="blue" />
          </div>
          <div className="dash-card" style={{ padding: '16px 20px' }}>
            <div className="dash-occupancy">
              {m.occupiedBeds > 0 && <div className="dash-occupancy__seg dash-occupancy__seg--occupied" style={{ width: `${(m.occupiedBeds / m.totalBeds) * 100}%` }} />}
              {m.cleaningBeds > 0 && <div className="dash-occupancy__seg dash-occupancy__seg--cleaning" style={{ width: `${(m.cleaningBeds / m.totalBeds) * 100}%` }} />}
              {m.availableBeds > 0 && <div className="dash-occupancy__seg dash-occupancy__seg--available" style={{ width: `${(m.availableBeds / m.totalBeds) * 100}%` }} />}
            </div>
            <div className="dash-occupancy__legend">
              <span><span className="dash-occupancy__legend-dot" style={{ background: BLUE }} /> Occupied ({m.occupiedBeds})</span>
              <span><span className="dash-occupancy__legend-dot" style={{ background: GREEN }} /> Available ({m.availableBeds})</span>
              {m.cleaningBeds > 0 && <span><span className="dash-occupancy__legend-dot" style={{ background: AMBER }} /> Cleaning ({m.cleaningBeds})</span>}
            </div>
          </div>
        </div>
      )}

      {/* ═══ PHARMACY + LAB ═══ */}
      <div className="dash-section dash-animate">
        <div className="dash-section__head">
          <h2 className="dash-section__title">Pharmacy & laboratory</h2>
        </div>
        <div className="dash-hero-kpis" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
          <HeroKpi label="Prescriptions" value={m.prescriptionsToday} icon={Pill} color="blue" />
          <HeroKpi label="Low stock" value={m.lowStockItems} icon={AlertTriangle} color={m.lowStockItems > 0 ? 'red' : 'green'} />
          <HeroKpi label="Critical values" value={m.criticalValues} icon={AlertTriangle} color={m.criticalValues > 0 ? 'red' : 'green'} />
        </div>
      </div>

      {/* ═══ APPOINTMENTS BY STATUS + RECENT ACTIVITY ═══ */}
      {c.appointmentsByStatus.length > 0 && (
        <div className="dash-charts dash-animate">
          <div className="dash-chart">
            <div className="dash-chart__head">
              <div>
                <p className="dash-chart__title">Appointments today</p>
                <p className="dash-chart__sub">By status</p>
              </div>
            </div>
            <div className="dash-chart__body">
              <ResponsiveContainer width="100%" height={200}>
                <PieChart>
                  <Pie data={c.appointmentsByStatus} dataKey="count" nameKey="status" cx="50%" cy="50%" innerRadius={52} outerRadius={78} paddingAngle={2}>
                    {c.appointmentsByStatus.map((_e, i) => <Cell key={i} fill={CHART_PALETTE[i % CHART_PALETTE.length]} />)}
                  </Pie>
                  <Tooltip contentStyle={{ fontSize: 12, background: 'var(--surface-card)' }} />
                </PieChart>
              </ResponsiveContainer>
              <div style={{ display: 'flex', justifyContent: 'center', gap: 14, fontSize: 12, flexWrap: 'wrap', color: 'var(--text-secondary)' }}>
                {c.appointmentsByStatus.map((item, i) => (
                  <span key={i} style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                    <span style={{ width: 7, height: 7, borderRadius: '50%', background: CHART_PALETTE[i % CHART_PALETTE.length], display: 'inline-block' }} />
                    {item.status} ({item.count})
                  </span>
                ))}
              </div>
            </div>
          </div>

          <div className="dash-chart">
            <div className="dash-chart__head">
              <div>
                <p className="dash-chart__title">Radiology & lab</p>
                <p className="dash-chart__sub">Workload overview</p>
              </div>
            </div>
            <div className="dash-chart__body" style={{ display: 'flex', flexDirection: 'column', gap: 16, justifyContent: 'center' }}>
              <MiniStat icon={Image} label="Pending studies" value={m.pendingStudies} color="blue" />
              <MiniStat icon={CheckCircle} label="Completed today" value={m.completedStudiesToday} color="green" />
              <MiniStat icon={FileText} label="Reports pending" value={m.pendingReports} color="amber" />
              <MiniStat icon={TestTube} label="Pending lab orders" value={m.pendingLabOrders} color="blue" />
            </div>
          </div>
        </div>
      )}

      {/* ═══ TABLES ═══ */}
      <div className="dash-tables dash-animate">
        {c.recentAdmissions.length > 0 && (
          <div className="dash-card">
            <div className="dash-card__head">
              <p className="dash-card__title">Recent admissions</p>
            </div>
            <div className="dash-card__body">
              <table className="dash-table">
                <thead><tr><th>Patient</th><th>Ward</th><th>Admitted</th><th>Status</th></tr></thead>
                <tbody>
                  {c.recentAdmissions.slice(0, 6).map((a, i) => (
                    <tr key={i}>
                      <td>{a.patientName}</td>
                      <td>{a.ward}</td>
                      <td>{timeAgo(a.admittedAt)}</td>
                      <td><StatusBadge status={a.status} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {c.lowStockMedications.length > 0 && (
          <div className="dash-card">
            <div className="dash-card__head">
              <p className="dash-card__title">Low stock medications</p>
            </div>
            <div className="dash-card__body">
              <table className="dash-table">
                <thead><tr><th>Medication</th><th>Form</th><th style={{ textAlign: 'right' }}>In stock</th><th style={{ textAlign: 'right' }}>Reorder</th></tr></thead>
                <tbody>
                  {c.lowStockMedications.slice(0, 6).map((med, i) => (
                    <tr key={i}>
                      <td>{med.name}</td>
                      <td>{med.form}</td>
                      <td className="align-right">{med.quantity}</td>
                      <td className="align-right">{med.reorderLevel}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

/* ═══ Sub-components ═══ */

function HeroKpi({ label, value, icon: Icon, color = 'blue', trend }: {
  label: string;
  value: string | number;
  icon: any;
  color?: string;
  trend?: { text: string; dir: 'up' | 'down' | 'neutral' };
}) {
  const [pop, setPop] = useState(false);
  const prev = useRef(value);
  useEffect(() => {
    if (prev.current !== value) {
      setPop(true);
      prev.current = value;
      const t = setTimeout(() => setPop(false), 300);
      return () => clearTimeout(t);
    }
  }, [value]);

  const TrendIcon = trend?.dir === 'up' ? TrendingUp : trend?.dir === 'down' ? TrendingDown : TrendingUp;

  return (
    <div className="dash-hero-kpi">
      <div className="dash-hero-kpi__top">
        <span className="dash-hero-kpi__label">{label}</span>
        <span className={`dash-hero-kpi__icon dash-hero-kpi__icon--${color}`}><Icon size={16} /></span>
      </div>
      <span className={`dash-hero-kpi__value ${pop ? 'dash-number-pop' : ''}`}>
        {typeof value === 'number' ? value.toLocaleString() : value}
      </span>
      {trend && (
        <span className={`dash-hero-kpi__trend dash-hero-kpi__trend--${trend.dir}`}>
          <TrendIcon size={12} /> {trend.text}
        </span>
      )}
    </div>
  );
}

function QueueStat({ label, value, sub, color = 'blue' }: {
  label: string;
  value: number;
  sub: string;
  color?: string;
}) {
  return (
    <div className="dash-queue__stat">
      <span className="dash-queue__stat-label">{label}</span>
      <span className="dash-queue__stat-value" style={{ color: `var(--${color === 'amber' ? 'amber' : color === 'green' ? 'green' : 'blue'}-600)` }}>
        {value}
      </span>
      <span className="dash-queue__stat-sub">{sub}</span>
    </div>
  );
}

function MiniStat({ icon: Icon, label, value, color }: {
  icon: any;
  label: string;
  value: number;
  color: string;
}) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
      <span className={`dash-hero-kpi__icon dash-hero-kpi__icon--${color}`} style={{ width: 32, height: 32 }}>
        <Icon size={14} />
      </span>
      <div>
        <div style={{ fontSize: 20, fontWeight: 700, fontFamily: "'Manrope', sans-serif", color: 'var(--text-primary)', lineHeight: 1 }}>
          {value.toLocaleString()}
        </div>
        <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>{label}</div>
      </div>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  return (
    <span className={`dash-appt__status dash-appt__status--${status}`}>
      <span className="dash-appt__dot" />
      {status.replace(/_/g, ' ')}
    </span>
  );
}
