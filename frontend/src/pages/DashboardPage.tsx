import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useAuth } from '../auth/AuthProvider';
import {
  Users, Calendar, Activity, Clock, Bed, DollarSign, Pill, TestTube,
  Image, AlertTriangle, Stethoscope, CheckCircle, FileText,
  TrendingUp, TrendingDown, Building2, Globe,
} from 'lucide-react';
import {
  AreaChart, Area, BarChart, Bar,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  PieChart, Pie, Cell,
} from 'recharts';
import {
  KpiCard, ChartCard, TableCard, AlertCard, FilterBar,
  DashboardSection, OccupancyBar, DashboardSkeleton,
} from '../components/dashboard';
import { dashboardApi, type DashboardMetrics, type ChartData } from '../api/dashboard';
import './dashboard-premium.css';

const COLORS = {
  blue: '#4FA9FF',
  green: '#12B76A',
  red: '#F04438',
  amber: '#F79009',
  gray: '#98A2B3',
  blueLight: '#D1E9FF',
  greenLight: '#D1FADF',
};

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

function hasRole(user: any, ...roles: string[]) {
  return user?.assignments?.some((a: any) => roles.includes(a.role?.code)) ?? false;
}

export function DashboardPage() {
  const { selectedFacilityId, selectedFacilityName, facilities } = useTenant();
  const { user, assignments } = useAuth();
  const fac = selectedFacilityId;

  const isPlatform = assignments.length === 0 || (!fac && facilities.length === 0);

  const [metrics, setMetrics] = useState<DashboardMetrics | null>(null);
  const [charts, setCharts] = useState<ChartData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [dateStart, setDateStart] = useState(() => dateRange(30).start);
  const [dateEnd, setDateEnd] = useState(() => dateRange(30).end);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [metricsRes, chartsRes] = await Promise.allSettled([
        dashboardApi.metrics(fac),
        dashboardApi.chartData(fac, 30),
      ]);
      if (metricsRes.status === 'fulfilled') {
        setMetrics(metricsRes.value as DashboardMetrics);
      } else {
        setError('Failed to load dashboard metrics');
      }
      if (chartsRes.status === 'fulfilled') setCharts(chartsRes.value as ChartData);
    } catch (e) {
      setError('Failed to load dashboard data');
    } finally {
      setLoading(false);
    }
  }, [fac]);

  useEffect(() => {
    fetchData();
    const interval = setInterval(fetchData, 60000);
    return () => clearInterval(interval);
  }, [fetchData]);

  const _roleFlags = {
    isDoctor: hasRole(user, 'doctor', 'physician', 'specialist'),
    isNurse: hasRole(user, 'nurse', 'nursing'),
    isPharmacist: hasRole(user, 'pharmacist', 'pharmacy'),
    isLabTech: hasRole(user, 'lab_technician', 'laboratory', 'lab'),
    isReception: hasRole(user, 'receptionist', 'front_desk'),
    isBilling: hasRole(user, 'billing', 'billing_clerk', 'accountant'),
    isAdmin: hasRole(user, 'admin', 'hospital_admin', 'organization_admin'),
    isFinance: hasRole(user, 'finance', 'finance_manager'),
  };
  void _roleFlags;

  if (loading && !metrics) {
    return (
      <div className="dashboard">
        <div className="dashboard-header">
          <div className="dashboard-header__text">
            <h1>{greeting()}, {user?.email?.split('@')[0] || 'User'}</h1>
            <p>Loading hospital dashboard...</p>
          </div>
        </div>
        <div className="kpi-grid">
          {Array.from({ length: 6 }).map((_, i) => <DashboardSkeleton key={i} type="kpi" />)}
        </div>
        <div className="chart-grid">
          <DashboardSkeleton type="chart" />
          <DashboardSkeleton type="chart" />
        </div>
      </div>
    );
  }

  if (error && !metrics) {
    return (
      <div className="dashboard">
        <div className="dashboard-header">
          <div className="dashboard-header__text">
            <h1>{greeting()}, {user?.email?.split('@')[0] || 'User'}</h1>
            <p>{error}</p>
          </div>
          <div className="dashboard-header__actions">
            <Link to="/patients/new" className="btn btn--primary btn--sm">
              <Users size={14} /> New Patient
            </Link>
            <Link to="/appointments" className="btn btn--secondary btn--sm">
              <Calendar size={14} /> Appointments
            </Link>
          </div>
        </div>
        <button className="btn btn--secondary" onClick={fetchData} style={{ margin: '0 1.5rem' }}>Retry</button>
      </div>
    );
  }

  if (!metrics) {
    return (
      <div className="dashboard">
        <div className="dashboard-header">
          <div className="dashboard-header__text">
            <h1>{greeting()}, {user?.email?.split('@')[0] || 'User'}</h1>
            <p>No data available</p>
          </div>
          <div className="dashboard-header__actions">
            <Link to="/patients/new" className="btn btn--primary btn--sm">
              <Users size={14} /> New Patient
            </Link>
            <Link to="/appointments" className="btn btn--secondary btn--sm">
              <Calendar size={14} /> Appointments
            </Link>
          </div>
        </div>
        <div className="kpi-grid">
          {Array.from({ length: 6 }).map((_, i) => <DashboardSkeleton key={i} type="kpi" />)}
        </div>
      </div>
    );
  }

  const m = metrics;
  const c: ChartData = charts ?? {
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

  // Platform stats from backend
  const platformStats = (m as any).totalOrganizations !== undefined
    ? { totalOrganizations: (m as any).totalOrganizations, totalFacilities: (m as any).totalFacilities }
    : null;

  return (
    <div className="dashboard">
      {/* ── Header ── */}
      <div className="dashboard-header">
        <div className="dashboard-header__text">
          <h1>{greeting()}, {user?.email?.split('@')[0] || 'User'}</h1>
          <p>
            {isPlatform
              ? 'Platform overview — all organizations and facilities'
              : selectedFacilityName
                ? `Facility: ${selectedFacilityName}`
                : new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
            }
          </p>
        </div>
        <div className="dashboard-header__actions">
          <Link to="/patients/new" className="btn btn--primary btn--sm">
            <Users size={14} /> New Patient
          </Link>
          <Link to="/appointments" className="btn btn--secondary btn--sm">
            <Calendar size={14} /> Appointments
          </Link>
        </div>
      </div>

      {/* ── Alerts ── */}
      {m.criticalValues > 0 && (
        <AlertCard
          type="danger"
          title={`${m.criticalValues} critical lab value${m.criticalValues > 1 ? 's' : ''} pending acknowledgment`}
          message="Review and acknowledge critical laboratory results immediately"
          icon={AlertTriangle}
        />
      )}
      {m.lowStockItems > 0 && (
        <AlertCard
          type="warning"
          title={`${m.lowStockItems} medication${m.lowStockItems > 1 ? 's' : ''} below reorder level`}
          message="Review pharmacy inventory and generate purchase orders"
          icon={Pill}
        />
      )}
      {m.erWaiting > 0 && (
        <AlertCard
          type="warning"
          title={`${m.erWaiting} patient${m.erWaiting > 1 ? 's' : ''} waiting in emergency`}
          message="Emergency department has patients awaiting triage or consultation"
          icon={Clock}
        />
      )}

      {/* ── Filter Bar ── */}
      <FilterBar
        dateRange={{ start: dateStart, end: dateEnd, onChange: (s, e) => { setDateStart(s); setDateEnd(e); } }}
      />

      {/* ── Platform Overview (superadmin) ── */}
      {isPlatform && platformStats && (
        <DashboardSection title="Platform Overview" subtitle="All organizations and facilities">
          <div className="kpi-grid">
            <KpiCard label="Organizations" value={platformStats.totalOrganizations} icon={Building2} iconColor="blue" />
            <KpiCard label="Facilities" value={platformStats.totalFacilities} icon={Globe} iconColor="green" />
            <KpiCard
              label="Total Patients"
              value={m.totalPatients}
              icon={Users}
              iconColor="blue"
              trend={m.newPatientsToday > 0 ? { value: `+${m.newPatientsToday} today`, direction: 'up' as const } : undefined}
            />
            <KpiCard label="Appointments Today" value={m.appointmentsToday} icon={Calendar} iconColor="blue" />
            <KpiCard
              label="Revenue This Month"
              value={formatCurrency(m.revenueThisMonth)}
              icon={DollarSign}
              iconColor="green"
              trend={m.revenueToday > 0 ? { value: `${formatCurrency(m.revenueToday)} today`, direction: 'up' as const } : undefined}
            />
            <KpiCard label="Notifications" value={m.unreadNotifications} icon={Activity} iconColor={m.unreadNotifications > 5 ? 'red' : 'gray'} />
          </div>
        </DashboardSection>
      )}

      {/* ── Core KPIs ── */}
      {!isPlatform && (
        <DashboardSection title="Today's Operations">
          <div className="kpi-grid">
            <KpiCard
              label="Appointments"
              value={m.appointmentsToday}
              icon={Calendar}
              iconColor="blue"
              trend={m.completedToday > 0 ? { value: `${m.completedToday} completed`, direction: 'up' } : undefined}
            />
            <KpiCard
              label="In Queue"
              value={m.inQueue + m.inConsultation}
              icon={Clock}
              iconColor={m.inQueue > 10 ? 'amber' : 'blue'}
            />
            <KpiCard
              label="Encounters"
              value={m.encountersToday}
              icon={Stethoscope}
              iconColor="green"
              trend={{ value: `${m.encountersThisWeek} this week`, direction: 'neutral' }}
            />
            <KpiCard
              label="Total Patients"
              value={m.totalPatients}
              icon={Users}
              iconColor="blue"
              trend={m.newPatientsToday > 0 ? { value: `+${m.newPatientsToday} today`, direction: 'up' } : undefined}
            />
            <KpiCard
              label="Revenue Today"
              value={formatCurrency(m.revenueToday)}
              icon={DollarSign}
              iconColor="green"
              trend={m.outstandingAmount > 0 ? { value: `${formatCurrency(m.outstandingAmount)} outstanding`, direction: 'down' } : undefined}
            />
            <KpiCard
              label="Notifications"
              value={m.unreadNotifications}
              icon={Activity}
              iconColor={m.unreadNotifications > 5 ? 'red' : 'gray'}
            />
          </div>
        </DashboardSection>
      )}

      {/* ── Charts Row 1: Patient Volume + Revenue ── */}
      <div className="chart-grid">
        <ChartCard title="Patient Registrations" subtitle="Last 30 days">
          {c.patientVolume.length > 0 ? (
            <ResponsiveContainer width="100%" height={240}>
              <AreaChart data={c.patientVolume}>
                <defs>
                  <linearGradient id="blueGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor={COLORS.blue} stopOpacity={0.15} />
                    <stop offset="95%" stopColor={COLORS.blue} stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--gray-100)" />
                <XAxis dataKey="date" tick={{ fontSize: 11, fill: 'var(--text-tertiary)' }} tickFormatter={(v) => v.slice(5)} />
                <YAxis tick={{ fontSize: 11, fill: 'var(--text-tertiary)' }} width={35} />
                <Tooltip
                  contentStyle={{ fontSize: 12, border: '1px solid var(--gray-200)', borderRadius: 8, boxShadow: '0 4px 12px rgba(0,0,0,0.06)' }}
                  formatter={(v) => [Number(v), 'Patients']}
                  labelFormatter={(l) => `Date: ${l}`}
                />
                <Area type="monotone" dataKey="value" stroke={COLORS.blue} strokeWidth={2} fill="url(#blueGrad)" />
              </AreaChart>
            </ResponsiveContainer>
          ) : (
            <div className="dashboard-empty" style={{ height: 240 }}>
              <p className="dashboard-empty__title">No patient registration data</p>
              <p className="dashboard-empty__message">Data will appear as patients are registered</p>
            </div>
          )}
        </ChartCard>

        <ChartCard title="Revenue Trend" subtitle="Paid invoices">
          {c.revenueTrend.length > 0 ? (
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={c.revenueTrend}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--gray-100)" />
                <XAxis dataKey="date" tick={{ fontSize: 11, fill: 'var(--text-tertiary)' }} tickFormatter={(v) => v.slice(5)} />
                <YAxis tick={{ fontSize: 11, fill: 'var(--text-tertiary)' }} width={50} tickFormatter={(v) => `${(v / 100).toFixed(0)}K`} />
                <Tooltip
                  contentStyle={{ fontSize: 12, border: '1px solid var(--gray-200)', borderRadius: 8 }}
                  formatter={(v) => [formatCurrency(Number(v)), 'Revenue']}
                />
                <Bar dataKey="value" fill={COLORS.blue} radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <div className="dashboard-empty" style={{ height: 240 }}>
              <p className="dashboard-empty__title">No revenue data</p>
              <p className="dashboard-empty__message">Revenue will appear as invoices are paid</p>
            </div>
          )}
        </ChartCard>
      </div>

      {/* ── IPD & Beds Section ── */}
      {m.totalBeds > 0 && (
        <DashboardSection title="Inpatient Department" subtitle="Bed occupancy overview">
          <div className="kpi-grid">
            <KpiCard label="Total Beds" value={m.totalBeds} icon={Bed} iconColor="gray" />
            <KpiCard
              label="Occupied"
              value={m.occupiedBeds}
              icon={Bed}
              iconColor="blue"
              trend={{ value: `${m.totalBeds > 0 ? Math.round((m.occupiedBeds / m.totalBeds) * 100) : 0}% occupancy`, direction: m.occupiedBeds > m.totalBeds * 0.9 ? 'down' : 'neutral' }}
            />
            <KpiCard label="Available" value={m.availableBeds} icon={Bed} iconColor="green" />
            <KpiCard label="Cleaning" value={m.cleaningBeds} icon={Bed} iconColor="amber" />
            <KpiCard label="Admissions Today" value={m.admissionsToday} icon={TrendingUp} iconColor="blue" />
            <KpiCard label="Discharges Today" value={m.dischargesToday} icon={TrendingDown} iconColor="green" />
          </div>
          <ChartCard title="Bed Occupancy">
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)' }}>
              <OccupancyBar occupied={m.occupiedBeds} available={m.availableBeds} cleaning={m.cleaningBeds} height={12} />
              <div style={{ display: 'flex', gap: 'var(--space-4)', fontSize: 'var(--text-caption)' }}>
                <span style={{ color: COLORS.blue }}>● Occupied ({m.occupiedBeds})</span>
                <span style={{ color: COLORS.green }}>● Available ({m.availableBeds})</span>
                {m.cleaningBeds > 0 && <span style={{ color: COLORS.amber }}>● Cleaning ({m.cleaningBeds})</span>}
              </div>
            </div>
          </ChartCard>
        </DashboardSection>
      )}

      {/* ── Pharmacy & Lab Section ── */}
      <DashboardSection title="Pharmacy & Laboratory">
        <div className="kpi-grid">
          <KpiCard label="Prescriptions Today" value={m.prescriptionsToday} icon={Pill} iconColor="blue" />
          <KpiCard label="Dispensings Today" value={m.dispensingsToday} icon={CheckCircle} iconColor="green" />
          <KpiCard
            label="Low Stock Items"
            value={m.lowStockItems}
            icon={AlertTriangle}
            iconColor={m.lowStockItems > 0 ? 'red' : 'green'}
          />
          <KpiCard label="Expiring Soon" value={m.expiringItems} icon={Clock} iconColor={m.expiringItems > 0 ? 'amber' : 'green'} />
          <KpiCard label="Pending Lab Orders" value={m.pendingLabOrders} icon={TestTube} iconColor="blue" />
          <KpiCard label="Critical Values" value={m.criticalValues} icon={AlertTriangle} iconColor={m.criticalValues > 0 ? 'red' : 'green'} />
        </div>
      </DashboardSection>

      {/* ── Radiology Section ── */}
      {(m.pendingStudies > 0 || m.completedStudiesToday > 0 || m.pendingReports > 0) && (
        <DashboardSection title="Radiology">
          <div className="kpi-grid" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
            <KpiCard label="Pending Studies" value={m.pendingStudies} icon={Image} iconColor="blue" />
            <KpiCard label="Completed Today" value={m.completedStudiesToday} icon={CheckCircle} iconColor="green" />
            <KpiCard label="Reports Pending" value={m.pendingReports} icon={FileText} iconColor="amber" />
          </div>
        </DashboardSection>
      )}

      {/* ── Appointments by Status Chart ── */}
      {c.appointmentsByStatus.length > 0 && (
        <div className="chart-grid">
          <ChartCard title="Appointments Today" subtitle="By status">
            <ResponsiveContainer width="100%" height={200}>
              <PieChart>
                <Pie
                  data={c.appointmentsByStatus}
                  dataKey="count"
                  nameKey="status"
                  cx="50%"
                  cy="50%"
                  innerRadius={55}
                  outerRadius={80}
                  paddingAngle={2}
                >
                  {c.appointmentsByStatus.map((_entry, i) => (
                    <Cell key={i} fill={[COLORS.blue, COLORS.green, COLORS.amber, COLORS.red, COLORS.gray][i % 5]} />
                  ))}
                </Pie>
                <Tooltip contentStyle={{ fontSize: 12 }} />
              </PieChart>
            </ResponsiveContainer>
            <div style={{ display: 'flex', justifyContent: 'center', gap: 'var(--space-4)', fontSize: 'var(--text-caption)', flexWrap: 'wrap' }}>
              {c.appointmentsByStatus.map((item, i) => (
                <span key={i} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                  <span style={{ width: 8, height: 8, borderRadius: '50%', background: [COLORS.blue, COLORS.green, COLORS.amber, COLORS.red, COLORS.gray][i % 5], display: 'inline-block' }} />
                  {item.status} ({item.count})
                </span>
              ))}
            </div>
          </ChartCard>

          {/* ── Recent Activity ── */}
          <ChartCard title="Recent Patients" subtitle="Latest registrations">
            {c.recentPatients.length > 0 ? (
              <div className="activity-feed">
                {c.recentPatients.slice(0, 6).map((p) => (
                  <Link key={p.id} to={`/patients/${p.id}`} className="activity-feed__item" style={{ textDecoration: 'none', color: 'inherit' }}>
                    <div className="activity-feed__dot activity-feed__dot--blue" />
                    <span className="activity-feed__text">
                      <strong>{p.name}</strong> <span style={{ color: 'var(--text-tertiary)' }}>({p.mrn})</span>
                    </span>
                    <span className="activity-feed__time">{timeAgo(p.lastVisit)}</span>
                  </Link>
                ))}
              </div>
            ) : (
              <div className="dashboard-empty" style={{ padding: 'var(--space-6)' }}>
                <p className="dashboard-empty__title">No recent patients</p>
              </div>
            )}
          </ChartCard>
        </div>
      )}

      {/* ── Tables Row ── */}
      <div className="chart-grid">
        {c.upcomingAppointments.length > 0 && (
          <TableCard
            title="Upcoming Appointments"
            columns={[
              { key: 'patientName', label: 'Patient' },
              { key: 'time', label: 'Time', render: (r) => new Date(String((r as any).time)).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) },
              { key: 'type', label: 'Type' },
              { key: 'status', label: 'Status', render: (r) => <StatusDot status={String(r.status)} /> },
            ]}
            data={c.upcomingAppointments as any}
            maxRows={8}
          />
        )}

        {c.recentAdmissions.length > 0 && (
          <TableCard
            title="Recent Admissions"
            columns={[
              { key: 'patientName', label: 'Patient' },
              { key: 'ward', label: 'Ward' },
              { key: 'admittedAt', label: 'Admitted', render: (r) => timeAgo(String((r as any).admittedAt)) },
              { key: 'status', label: 'Status', render: (r) => <StatusDot status={String(r.status)} /> },
            ]}
            data={c.recentAdmissions as any}
            maxRows={8}
          />
        )}

        {c.lowStockMedications.length > 0 && (
          <TableCard
            title="Low Stock Medications"
            columns={[
              { key: 'name', label: 'Medication' },
              { key: 'form', label: 'Form' },
              { key: 'quantity', label: 'In Stock', align: 'right' },
              { key: 'reorderLevel', label: 'Reorder Level', align: 'right' },
            ]}
            data={c.lowStockMedications as any}
            maxRows={8}
            emptyMessage="All medications adequately stocked"
          />
        )}
      </div>
    </div>
  );
}

/* ── Status Dot helper ── */
function StatusDot({ status }: { status: string }) {
  const colors: Record<string, string> = {
    booked: COLORS.blue, completed: COLORS.green, cancelled: COLORS.red,
    no_show: COLORS.red, checked_in: COLORS.green, in_consultation: COLORS.blue,
    waiting: COLORS.amber, admitted: COLORS.blue, discharged: COLORS.green,
  };
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 'var(--text-caption)' }}>
      <span style={{ width: 6, height: 6, borderRadius: '50%', background: colors[status] ?? COLORS.gray }} />
      {status.replace(/_/g, ' ')}
    </span>
  );
}
