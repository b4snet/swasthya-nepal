import { useState, useEffect, useCallback, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useAccess } from '../auth/useAccess';
import {
  Users, Calendar, Clock, Bed, DollarSign, Pill, TestTube,
  Image, AlertTriangle, Stethoscope, CheckCircle, FileText,
  TrendingUp, TrendingDown, Building2, Globe,
  ArrowRight, Siren, ClipboardList, ScanLine, Landmark, Boxes,
} from 'lucide-react';
import {
  AreaChart, Area, BarChart, Bar,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  PieChart, Pie, Cell,
} from 'recharts';
import { dashboardApi, type DashboardMetrics, type ChartData } from '../api/dashboard';
import './dashboard-premium.css';

/* ── Constants ── */
const BLUE = '#0d9488'; // teal-600 — clinical accent
const GREEN = '#12b76a';
const RED = '#f04438';
const AMBER = '#f79009';
const GRAY = '#98a2b3';
const CHART_PALETTE = [BLUE, GREEN, AMBER, RED, GRAY, '#8b5cf6', '#0d9488'];

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



/* ── Workspace Quick Actions (role-specific) ── */
interface WorkspaceLink {
  label: string;
  to: string;
  icon: any;
  color: string;
}

const ROLE_WORKSPACES: Array<{ roles: string[]; title: string; description: string; links: WorkspaceLink[] }> = [
  {
    roles: ['doctor'],
    title: 'Clinical Workspace',
    description: 'Your patients, encounters, and clinical tasks',
    links: [
      { label: 'Patients', to: '/clinical/patients', icon: Users, color: BLUE },
      { label: 'Appointments', to: '/clinical/appointments', icon: Calendar, color: GREEN },
      { label: 'Encounters', to: '/clinical/encounters', icon: Stethoscope, color: AMBER },
      { label: 'Queue', to: '/clinical/queue', icon: ClipboardList, color: '#8b5cf6' },
    ],
  },
  {
    roles: ['nurse'],
    title: 'Nursing Workspace',
    description: 'Patient care, vitals, and nursing tasks',
    links: [
      { label: 'Nursing', to: '/nursing', icon: ClipboardList, color: BLUE },
      { label: 'Patients', to: '/clinical/patients', icon: Users, color: GREEN },
      { label: 'Queue', to: '/clinical/queue', icon: Clock, color: AMBER },
    ],
  },
  {
    roles: ['pharmacist'],
    title: 'Pharmacy Workspace',
    description: 'Prescriptions, dispensing, and inventory',
    links: [
      { label: 'Prescriptions', to: '/pharmacy/prescriptions', icon: Pill, color: BLUE },
      { label: 'Dispensing', to: '/pharmacy/dispensing', icon: Pill, color: GREEN },
      { label: 'Inventory', to: '/pharmacy/inventory', icon: Boxes, color: AMBER },
    ],
  },
  {
    roles: ['lab_technician', 'lab_supervisor'],
    title: 'Laboratory Workspace',
    description: 'Lab orders, results, and specimens',
    links: [
      { label: 'Orders', to: '/laboratory/orders', icon: TestTube, color: BLUE },
      { label: 'Reports', to: '/laboratory/reports', icon: FileText, color: GREEN },
    ],
  },
  {
    roles: ['radiographer', 'radiologist'],
    title: 'Radiology Workspace',
    description: 'Imaging studies and reports',
    links: [
      { label: 'Worklist', to: '/radiology', icon: ScanLine, color: BLUE },
    ],
  },
  {
    roles: ['billing_clerk'],
    title: 'Finance Workspace',
    description: 'Billing, collections, and financial operations',
    links: [
      { label: 'Billing', to: '/finance/billing', icon: DollarSign, color: BLUE },
      { label: 'Revenue Cycle', to: '/finance/revenue', icon: TrendingUp, color: GREEN },
    ],
  },
  {
    roles: ['hospital_admin', 'branch_manager'],
    title: 'Hospital Operations',
    description: 'Facility management and operations',
    links: [
      { label: 'Hospital Dashboard', to: '/hospital', icon: Building2, color: BLUE },
      { label: 'Emergency', to: '/emergency', icon: Siren, color: RED },
      { label: 'Beds', to: '/beds', icon: Bed, color: GREEN },
    ],
  },
  {
    roles: ['receptionist'],
    title: 'Front Desk',
    description: 'Patient registration and appointment management',
    links: [
      { label: 'Patients', to: '/clinical/patients', icon: Users, color: BLUE },
      { label: 'Appointments', to: '/clinical/appointments', icon: Calendar, color: GREEN },
      { label: 'Queue', to: '/clinical/queue', icon: ClipboardList, color: AMBER },
    ],
  },
  {
    roles: ['superadmin', 'org_admin', 'org_finance', 'support_agent'],
    title: 'Administration',
    description: 'Hospital administration',
    links: [
      { label: 'Administration', to: '/admin', icon: Building2, color: BLUE },
      { label: 'Finance Settings', to: '/finance/nepal-admin', icon: Landmark, color: GREEN },
      { label: 'Reports', to: '/reports', icon: FileText, color: AMBER },
    ],
  },
];

function WorkspaceQuickActions({ roles }: { roles: string[] }) {
  const workspace = ROLE_WORKSPACES.find((w) => w.roles.some((r) => roles.includes(r)));
  if (!workspace) return null;

  return (
    <div className="dash-section dash-animate">
      <div className="dash-section__head">
        <h2 className="dash-section__title">{workspace.title}</h2>
        <p className="dash-section__sub">{workspace.description}</p>
      </div>
      <div className="dash-hero-kpis" style={{ gridTemplateColumns: `repeat(${Math.min(workspace.links.length, 4)}, 1fr)` }}>
        {workspace.links.map((link) => (
          <Link
            key={link.to}
            to={link.to}
            className="dash-hero-kpi dash-hero-kpi--link"
            style={{ textDecoration: 'none', color: 'inherit' }}
          >
            <div className="dash-hero-kpi__top">
              <span className="dash-hero-kpi__label" style={{ fontSize: 13, fontWeight: 600 }}>{link.label}</span>
              <span className={`dash-hero-kpi__icon dash-hero-kpi__icon--${link.color === BLUE ? 'blue' : link.color === GREEN ? 'green' : link.color === RED ? 'red' : link.color === AMBER ? 'amber' : 'blue'}`}><link.icon size={16} /></span>
            </div>
            <span className="dash-hero-kpi__trend" style={{ color: 'var(--blue-600)', fontSize: 12, marginTop: 4 }}>
              Open <ArrowRight size={12} />
            </span>
          </Link>
        ))}
      </div>
    </div>
  );
}



/* Role-specific My Work KPIs */
interface RoleKpiConfig {
  roles: string[];
  title: string;
  subtitle: string;
  kpis: Array<{
    label: string;
    getValue: (m: DashboardMetrics) => string | number;
    icon: any;
    color: string | ((m: DashboardMetrics) => string);
    getTrend?: (m: DashboardMetrics) => { text: string; dir: 'up' | 'down' | 'neutral' } | undefined;
  }>;
}

const ROLE_KPI_CONFIGS: RoleKpiConfig[] = [
  {
    roles: ['doctor'],
    title: 'My Day',
    subtitle: 'Your clinical workload',
    kpis: [
      { label: 'Appointments', getValue: (m) => m.appointmentsToday, icon: Calendar, color: 'blue',
        getTrend: (m) => ({ text: `${m.completedToday} completed`, dir: 'up' as const }) },
      { label: 'Waiting', getValue: (m) => m.inQueue, icon: Clock, color: (m) => m.inQueue > 5 ? 'amber' : 'blue',
        getTrend: (m) => ({ text: `avg ${m.avgWaitMinutes}min`, dir: 'neutral' as const }) },
      { label: 'Encounters', getValue: (m) => m.encountersToday, icon: Stethoscope, color: 'green',
        getTrend: (m) => ({ text: `${m.encountersThisWeek} this week`, dir: 'up' as const }) },
      { label: 'Results to review', getValue: (m) => m.criticalValues + m.pendingLabOrders, icon: TestTube,
        color: (m) => m.criticalValues > 0 ? 'red' : 'blue' },
    ],
  },
  {
    roles: ['nurse'],
    title: 'My Shift',
    subtitle: 'Patient care and nursing tasks',
    kpis: [
      { label: 'In queue', getValue: (m) => m.inQueue + m.inConsultation, icon: Clock, color: 'blue' },
      { label: 'Encounters', getValue: (m) => m.encountersToday, icon: Stethoscope, color: 'green' },
      { label: 'Inpatients', getValue: (m) => m.occupiedBeds, icon: Bed, color: 'blue' },
      { label: 'Critical values', getValue: (m) => m.criticalValues, icon: AlertTriangle,
        color: (m) => m.criticalValues > 0 ? 'red' : 'green' },
    ],
  },
  {
    roles: ['pharmacist'],
    title: 'Pharmacy Today',
    subtitle: 'Prescriptions and inventory',
    kpis: [
      { label: 'Prescriptions', getValue: (m) => m.prescriptionsToday, icon: Pill, color: 'blue' },
      { label: 'Low stock', getValue: (m) => m.lowStockItems, icon: AlertTriangle,
        color: (m) => m.lowStockItems > 0 ? 'red' : 'green' },
      { label: 'Encounters', getValue: (m) => m.encountersToday, icon: Stethoscope, color: 'green' },
      { label: 'Appointments', getValue: (m) => m.appointmentsToday, icon: Calendar, color: 'blue' },
    ],
  },
  {
    roles: ['lab_technician', 'lab_supervisor'],
    title: 'Laboratory Today',
    subtitle: 'Orders, specimens, and results',
    kpis: [
      { label: 'Pending orders', getValue: (m) => m.pendingLabOrders, icon: TestTube, color: 'blue' },
      { label: 'Critical values', getValue: (m) => m.criticalValues, icon: AlertTriangle,
        color: (m) => m.criticalValues > 0 ? 'red' : 'green' },
      { label: 'Appointments', getValue: (m) => m.appointmentsToday, icon: Calendar, color: 'blue' },
      { label: 'Results today', getValue: (m) => m.completedStudiesToday, icon: CheckCircle, color: 'green' },
    ],
  },
  {
    roles: ['radiographer', 'radiologist'],
    title: 'Radiology Today',
    subtitle: 'Studies and reporting',
    kpis: [
      { label: 'Pending studies', getValue: (m) => m.pendingStudies, icon: Image, color: 'blue' },
      { label: 'Completed today', getValue: (m) => m.completedStudiesToday, icon: CheckCircle, color: 'green' },
      { label: 'Reports pending', getValue: (m) => m.pendingReports, icon: FileText, color: 'amber' },
      { label: 'Appointments', getValue: (m) => m.appointmentsToday, icon: Calendar, color: 'blue' },
    ],
  },
  {
    roles: ['billing_clerk', 'finance_manager', 'accountant'],
    title: 'Finance Today',
    subtitle: 'Billing, collections, and revenue',
    kpis: [
      { label: 'Revenue', getValue: (m) => formatCurrency(m.revenueToday), icon: DollarSign, color: 'green' },
      { label: 'Outstanding', getValue: (m) => formatCurrency(m.outstandingAmount), icon: TrendingDown,
        color: (m) => m.outstandingAmount > 0 ? 'amber' : 'green' },
      { label: 'Appointments', getValue: (m) => m.appointmentsToday, icon: Calendar, color: 'blue' },
      { label: 'Encounters', getValue: (m) => m.encountersToday, icon: Stethoscope, color: 'blue' },
    ],
  },
  {
    roles: ['hospital_admin', 'branch_manager'],
    title: 'Hospital Today',
    subtitle: 'Operations overview',
    kpis: [
      { label: 'Appointments', getValue: (m) => m.appointmentsToday, icon: Calendar, color: 'blue' },
      { label: 'Encounters', getValue: (m) => m.encountersToday, icon: Stethoscope, color: 'green' },
      { label: 'Revenue', getValue: (m) => formatCurrency(m.revenueToday), icon: DollarSign, color: 'green' },
      { label: 'Occupancy', getValue: (m) => m.totalBeds > 0 ? `${Math.round((m.occupiedBeds / m.totalBeds) * 100)}%` : 'N/A',
        icon: Bed, color: 'blue' },
    ],
  },
  {
    roles: ['receptionist'],
    title: 'Front Desk',
    subtitle: 'Registration and patient flow',
    kpis: [
      { label: 'Check-ins', getValue: (m) => m.checkInsToday, icon: Users, color: 'green' },
      { label: 'In queue', getValue: (m) => m.inQueue, icon: Clock,
        color: (m) => m.inQueue > 10 ? 'amber' : 'blue' },
      { label: 'Appointments', getValue: (m) => m.appointmentsToday, icon: Calendar, color: 'blue' },
      { label: 'Waiting ER', getValue: (m) => m.erWaiting, icon: Siren,
        color: (m) => m.erWaiting > 0 ? 'red' : 'green' },
    ],
  },
];

/* Role-Specific Dashboard Content */
function RoleSpecificDashboard({ metrics, roles }: { metrics: DashboardMetrics; roles: string[] }) {
  const config = ROLE_KPI_CONFIGS.find(c => c.roles.some(r => roles.includes(r)));
  if (!config) return null;
  const m = metrics;

  return (
    <div className="dash-section dash-animate">
      <div className="dash-section__head">
        <h2 className="dash-section__title">{config.title}</h2>
        <p className="dash-section__sub">{config.subtitle}</p>
      </div>
      <div className="dash-hero-kpis" style={{ gridTemplateColumns: `repeat(${Math.min(config.kpis.length, 4)}, 1fr)` }}>
        {config.kpis.map((kpi, i) => {
          const rawColor = typeof kpi.color === 'function' ? kpi.color(m) : kpi.color;
          const value = kpi.getValue(m);
          const trend = kpi.getTrend?.(m);
          return (
            <HeroKpi
              key={i}
              label={kpi.label}
              value={value}
              icon={kpi.icon}
              color={rawColor}
              trend={trend}
            />
          );
        })}
      </div>
    </div>
  );
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
  const { selectedFacilityId, selectedFacilityName, roles } = useTenant();
  const access = useAccess();
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
            <h1 className="dash-pulse__greeting">{greeting()}, {access.getDisplayName()}</h1>
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
            <h1 className="dash-pulse__greeting">{greeting()}, {access.getDisplayName()}</h1>
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
  const platform = isPlatform ? {
    totalOrganizations: m.totalOrganizations ?? 0,
    totalFacilities: m.totalFacilities ?? 0,
    totalStaff: m.totalStaff ?? 0,
    totalUsers: m.totalUsers ?? 0,
    totalDepartments: m.totalDepartments ?? 0,
    platformAdmins: m.platformAdmins ?? 0,
    totalPatients: m.totalPatients ?? 0,
    totalRevenue: m.totalRevenue ?? 0,
    totalAppointments: m.totalAppointments ?? 0,
    organizations: m.organizations ?? [],
  } : null;

  const userName = access.getDisplayName();

  return (
    <div className="dashboard">
      {/* ═══ PULSE HEADER ═══ */}
      <div className="dash-pulse dash-animate">
        <div className="dash-pulse__left">
          <h1 className="dash-pulse__greeting">{greeting()}, {userName}</h1>
          <div className="dash-pulse__meta">
            <span>{isPlatform ? 'Hospital overview' : selectedFacilityName || formatDate(new Date())}</span>
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
      {isPlatform && platform && (
        <>
          {/* ── Platform KPI Row 1: Infrastructure ── */}
          <div className="dash-section dash-animate">
            <div className="dash-section__head">
              <h2 className="dash-section__title">Hospital infrastructure</h2>
              <p className="dash-section__sub">Hospital facilities, staff, and departments</p>
            </div>
            <div className="dash-hero-kpis">
              <HeroKpi label="Hospitals" value={platform.totalOrganizations} icon={Building2} color="blue" />
              <HeroKpi label="Facilities" value={platform.totalFacilities} icon={Globe} color="green" />
              <HeroKpi label="Active staff" value={platform.totalStaff} icon={Users} color="blue" />
              <HeroKpi label="Departments" value={platform.totalDepartments} icon={FileText} color="amber" />
            </div>
          </div>

          {/* ── Platform KPI Row 2: Operations ── */}
          <div className="dash-section dash-animate">
            <div className="dash-section__head">
              <h2 className="dash-section__title">Cross-platform operations</h2>
              <p className="dash-section__sub">Aggregate activity across all organizations</p>
            </div>
            <div className="dash-hero-kpis">
              <HeroKpi label="Total patients" value={platform.totalPatients} icon={Users} color="blue"
                trend={m.newPatientsToday > 0 ? { text: `+${m.newPatientsToday} today`, dir: 'up' } : undefined} />
              <HeroKpi label="Today's appointments" value={platform.totalAppointments} icon={Calendar} color="blue" />
              <HeroKpi label="Total revenue" value={formatCurrency(platform.totalRevenue)} icon={DollarSign} color="green"
                trend={m.revenueToday > 0 ? { text: `${formatCurrency(m.revenueToday)} today`, dir: 'up' } : undefined} />
              <HeroKpi label="Admins" value={platform.platformAdmins} icon={Stethoscope} color="amber" />
            </div>
          </div>

          {/* ── Hospital Breakdown Table ── */}
          {platform.organizations.length > 0 && (
            <div className="dash-section dash-animate">
              <div className="dash-section__head">
                <h2 className="dash-section__title">Organizations</h2>
                <p className="dash-section__sub">{platform.totalOrganizations} registered organization{platform.totalOrganizations !== 1 ? 's' : ''}</p>
              </div>
              <div className="dash-card">
                <div className="dash-card__body">
                  <table className="dash-table">
                    <thead>
                      <tr>
                        <th>Organization</th>
                        <th>Code</th>
                        <th style={{ textAlign: 'right' }}>Facilities</th>
                        <th style={{ textAlign: 'right' }}>Patients</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {platform.organizations.map((org) => (
                        <tr key={org.id}>
                          <td><strong>{org.name}</strong></td>
                          <td>{org.code || '—'}</td>
                          <td className="align-right">{org.facilityCount}</td>
                          <td className="align-right">{org.patientCount.toLocaleString()}</td>
                          <td>
                            <span className={`dash-appt__status dash-appt__status--${org.status === 'active' ? 'completed' : 'cancelled'}`}>
                              <span className="dash-appt__dot" />
                              {org.status}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}

          {/* ── System Alerts for Platform Admins ── */}
          {(m.criticalValues > 0 || m.lowStockItems > 0) && (
            <div className="dash-section dash-animate">
              <div className="dash-section__head">
                <h2 className="dash-section__title">System attention</h2>
                <p className="dash-section__sub">Issues requiring cross-platform oversight</p>
              </div>
              <div className="dash-hero-kpis" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
                <HeroKpi label="Critical lab values" value={m.criticalValues} icon={AlertTriangle}
                  color={m.criticalValues > 0 ? 'red' : 'green'} />
                <HeroKpi label="Low stock items" value={m.lowStockItems} icon={Pill}
                  color={m.lowStockItems > 0 ? 'amber' : 'green'} />
                <HeroKpi label="ER waiting" value={m.erWaiting} icon={Clock}
                  color={m.erWaiting > 0 ? 'amber' : 'green'} />
              </div>
            </div>
          )}
        </>
      )}

      {/* ═══ ROLE-SPECIFIC DASHBOARD ═══ */}
      {!isPlatform && (
        <RoleSpecificDashboard metrics={m} roles={roles} />
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

      {/* ═══ MY WORKSPACE ═══ */}
      {!isPlatform && (
        <WorkspaceQuickActions roles={roles} />
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
