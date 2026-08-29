import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useAccess } from '../auth/useAccess';
import { useFetch } from '../hooks/useFetch';
import { bedWardApi, erApi } from '../api/endpoints';
import { api } from '../api/client';
import { Button, Card, EmptyState } from '../components/ui';
import { HospitalCommandCenter } from '../components/HospitalCommandCenter';
import {
  Bed,
  Users,
  AlertTriangle,
  Clock,
  ArrowRight,
  Activity,
  RefreshCw,
  Package,
  HeartPulse,
  ScanLine,
  Pill,
  Scissors,
} from 'lucide-react';
import './hospital-ops.css';

/* ─── API helpers ─── */

const orchestrationApi = {
  dashboard: () => api.request<Record<string, unknown>>('/api/v1/orchestration/dashboard'),
  capacity: () => api.request<Record<string, unknown>>('/api/v1/orchestration/capacity'),
  patientFlow: () => api.request<Record<string, unknown>>('/api/v1/orchestration/patient-flow'),
};

/* ─── Types ─── */

interface BedInfo {
  id: string;
  bedCode: string;
  status: string;
  admissionId: string | null;
}

interface WardInfo {
  id: string;
  name: string;
  wardType: string;
  counts: Record<string, number>;
  rooms: Array<{
    id: string;
    name: string;
    roomType: string;
    counts: Record<string, number>;
    beds: BedInfo[];
  }>;
}

/* ─── Status helpers ─── */

const BED_STATUS = {
  available: { color: '#10b981', label: 'Available' },
  occupied: { color: '#ef4444', label: 'Occupied' },
  reserved: { color: '#f59e0b', label: 'Reserved' },
  cleaning: { color: '#3b82f6', label: 'Cleaning' },
  maintenance: { color: '#8b5cf6', label: 'Maintenance' },
  out_of_service: { color: '#6b7280', label: 'Out of Service' },
} as const;

/* ─── Capacity Section ─── */

function CapacityOverview({
  bedCap,
  appointments,
  flow,
  navigate,
}: {
  bedCap: Record<string, unknown>;
  appointments: Record<string, unknown>;
  flow: Record<string, unknown>;
  navigate: ReturnType<typeof useNavigate>;
}) {
  const totalBeds = (bedCap.total as number) ?? 0;
  const available = (bedCap.available as number) ?? 0;
  const occupancyPct = (bedCap.occupancy_pct as number) ?? 0;
  const todayAppts = (appointments.today as number) ?? 0;
  const remaining = (appointments.remaining as number) ?? 0;

  const flowArrivals = (flow.arrivals as number) ?? 0;
  const flowConsulting = (flow.in_consultation as number) ?? 0;
  const flowCompleted = (flow.completed as number) ?? 0;

  return (
    <div className="ops-capacity">
      <div className="ops-capacity-header">
        <h3>Hospital Capacity</h3>
        <span className="ops-subtitle">Real-time operational state</span>
      </div>

      <div className="ops-capacity-grid">
        {/* Bed Capacity */}
        <Card className="ops-capacity-card" onClick={() => navigate('/beds')}>
          <div className="ops-capacity-icon" style={{ background: '#eff6ff', color: '#3b82f6' }}>
            <Bed size={20} />
          </div>
          <div className="ops-capacity-info">
            <span className="ops-capacity-label">Bed Capacity</span>
            <div className="ops-capacity-numbers">
              <span className="ops-capacity-value" style={{ color: '#10b981' }}>{available}</span>
              <span className="ops-capacity-sep">/</span>
              <span className="ops-capacity-total">{totalBeds}</span>
              <span className="ops-capacity-pct" style={{ color: occupancyPct > 85 ? '#ef4444' : '#10b981' }}>
                {occupancyPct}% occupied
              </span>
            </div>
            <div className="ops-capacity-bar">
              <div className="ops-capacity-bar-fill" style={{ width: `${occupancyPct}%`, background: occupancyPct > 85 ? '#ef4444' : '#10b981' }} />
            </div>
          </div>
          <ArrowRight size={16} className="ops-arrow" />
        </Card>

        {/* Today's Flow */}
        <Card className="ops-capacity-card" onClick={() => navigate('/clinical/flow')}>
          <div className="ops-capacity-icon" style={{ background: '#f0fdf4', color: '#10b981' }}>
            <Activity size={20} />
          </div>
          <div className="ops-capacity-info">
            <span className="ops-capacity-label">Patient Flow Today</span>
            <div className="ops-capacity-numbers">
              <span className="ops-capacity-value" style={{ color: '#3b82f6' }}>{flowArrivals}</span>
              <span className="ops-capacity-desc">arrivals</span>
              <span className="ops-capacity-sep">→</span>
              <span className="ops-capacity-value" style={{ color: '#f59e0b' }}>{flowConsulting}</span>
              <span className="ops-capacity-desc">consulting</span>
            </div>
            <div className="ops-capacity-numbers">
              <span className="ops-capacity-desc">{flowCompleted} completed</span>
            </div>
          </div>
          <ArrowRight size={16} className="ops-arrow" />
        </Card>

        {/* Appointments */}
        <Card className="ops-capacity-card" onClick={() => navigate('/clinical/appointments')}>
          <div className="ops-capacity-icon" style={{ background: '#faf5ff', color: '#8b5cf6' }}>
            <Users size={20} />
          </div>
          <div className="ops-capacity-info">
            <span className="ops-capacity-label">Appointments</span>
            <div className="ops-capacity-numbers">
              <span className="ops-capacity-value" style={{ color: '#8b5cf6' }}>{todayAppts}</span>
              <span className="ops-capacity-desc">today</span>
              <span className="ops-capacity-sep">·</span>
              <span className="ops-capacity-value" style={{ color: '#f59e0b' }}>{remaining}</span>
              <span className="ops-capacity-desc">remaining</span>
            </div>
          </div>
          <ArrowRight size={16} className="ops-arrow" />
        </Card>

        {/* Queue */}
        <Card className="ops-capacity-card" onClick={() => navigate('/clinical/queue')}>
          <div className="ops-capacity-icon" style={{ background: '#fff7ed', color: '#f59e0b' }}>
            <Clock size={20} />
          </div>
          <div className="ops-capacity-info">
            <span className="ops-capacity-label">Queue</span>
            <div className="ops-capacity-numbers">
              <span className="ops-capacity-value" style={{ color: '#f59e0b' }}>
                {(flow.waiting_in_queue as number) ?? 0}
              </span>
              <span className="ops-capacity-desc">waiting</span>
              <span className="ops-capacity-sep">·</span>
              <span className="ops-capacity-value" style={{ color: '#3b82f6' }}>
                {(flow.in_queue_consultation as number) ?? 0}
              </span>
              <span className="ops-capacity-desc">in service</span>
            </div>
          </div>
          <ArrowRight size={16} className="ops-arrow" />
        </Card>
      </div>
    </div>
  );
}

/* ─── Ward Overview Section ─── */

function WardOverview({ wards }: { wards: WardInfo[] }) {
  const navigate = useNavigate();

  return (
    <div className="ops-section">
      <div className="ops-section-header">
        <h3>Ward Capacity</h3>
        <span className="ops-count">{wards.length} wards</span>
      </div>

      {wards.length === 0 ? (
        <EmptyState title="No wards configured" body="Configure wards and beds in hospital administration." />
      ) : (
        <div className="ops-ward-grid">
          {wards.map((ward) => {
            const total = ward.counts.available ?? 0 + (ward.counts.occupied ?? 0) + (ward.counts.reserved ?? 0) + (ward.counts.cleaning ?? 0) + (ward.counts.maintenance ?? 0) + (ward.counts.out_of_service ?? 0);
            const occupied = ward.counts.occupied ?? 0;
            const rate = total > 0 ? Math.round((occupied / total) * 100) : 0;

            return (
              <Card key={ward.id} className="ops-ward-card" onClick={() => navigate('/beds')}>
                <div className="ops-ward-header">
                  <div>
                    <h4>{ward.name}</h4>
                    <span className="ops-ward-type">{ward.wardType}</span>
                  </div>
                  <div className="ops-ward-rate" style={{ color: rate > 85 ? '#ef4444' : rate > 60 ? '#f59e0b' : '#10b981' }}>
                    {rate}%
                  </div>
                </div>
                <div className="ops-ward-beds">
                  {Object.entries(BED_STATUS).map(([key, cfg]) => {
                    const count = ward.counts[key] ?? 0;
                    if (count === 0) return null;
                    return (
                      <span key={key} className="ops-bed-badge" style={{ color: cfg.color, background: cfg.color + '15' }}>
                        <span className="ops-bed-dot" style={{ background: cfg.color }} />
                        {count} {cfg.label}
                      </span>
                    );
                  })}
                </div>
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}

/* ─── Patient Flow Section ─── */

function PatientFlowSection({ flow }: { flow: Record<string, unknown> }) {
  const navigate = useNavigate();

  const stages = [
    { label: 'Arrivals', value: (flow.arrivals as number) ?? 0, color: '#3b82f6', icon: Users, route: '/clinical/flow' },
    { label: 'Checked In', value: (flow.checked_in as number) ?? 0, color: '#8b5cf6', icon: Users, route: '/clinical/queue' },
    { label: 'Consulting', value: (flow.in_consultation as number) ?? 0, color: '#f59e0b', icon: Activity, route: '/clinical/encounters' },
    { label: 'Completed', value: (flow.completed as number) ?? 0, color: '#10b981', icon: Activity, route: '/clinical/encounters' },
    { label: 'Cancelled', value: (flow.cancelled as number) ?? 0, color: '#ef4444', icon: AlertTriangle, route: '/clinical/appointments' },
    { label: 'No Show', value: (flow.no_show as number) ?? 0, color: '#6b7280', icon: Clock, route: '/clinical/appointments' },
  ];

  return (
    <div className="ops-section">
      <div className="ops-section-header">
        <h3>Patient Flow</h3>
        <span className="ops-subtitle">Today</span>
      </div>
      <div className="ops-flow-strip">
        {stages.map((stage, i) => {
          const Icon = stage.icon;
          return (
            <div
              key={stage.label}
              className="ops-flow-stage"
              style={{ borderLeftColor: stage.color }}
              onClick={() => navigate(stage.route)}
            >
              <div className="ops-flow-header">
                <Icon size={14} style={{ color: stage.color }} />
                <span className="ops-flow-label">{stage.label}</span>
              </div>
              <span className="ops-flow-value" style={{ color: stage.color }}>{stage.value}</span>
              {i < stages.length - 1 && <span className="ops-flow-arrow">→</span>}
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ─── ER Queue Section ─── */

function ERQueueSection({ erQueue }: { erQueue: Array<{ encounterId: string; patientId: string; triageLevel: number | null; triageColor: string | null; presentingComplaint: string | null; registeredAt: string | null }> }) {
  const navigate = useNavigate();

  const sorted = useMemo(() =>
    [...erQueue].sort((a, b) => {
      const ta = a.triageLevel ?? 5;
      const tb = b.triageLevel ?? 5;
      return ta - tb;
    }),
    [erQueue],
  );

  return (
    <div className="ops-section">
      <div className="ops-section-header">
        <h3>Emergency Queue</h3>
        <span className="ops-badge" style={{ background: erQueue.length > 0 ? '#fef3c7' : '#f0fdf4', color: erQueue.length > 0 ? '#d97706' : '#16a34a' }}>
          {erQueue.length} waiting
        </span>
      </div>

      {sorted.length === 0 ? (
        <EmptyState title="No patients waiting" body="The ER queue is clear." />
      ) : (
        <div className="ops-er-list">
          {sorted.map((entry) => (
            <div key={entry.encounterId} className="ops-er-row" onClick={() => navigate(`/clinical/patients/${entry.patientId}`)}>
              {entry.triageLevel !== null && (
                <span className="ops-triage" style={{ background: entry.triageColor ?? '#6b7280' }}>
                  T{entry.triageLevel}
                </span>
              )}
              <div className="ops-er-info">
                <span className="ops-er-id">{entry.patientId.slice(0, 8)}...</span>
                {entry.presentingComplaint && (
                  <span className="ops-er-complaint">{entry.presentingComplaint}</span>
                )}
              </div>
              {entry.registeredAt && (
                <span className="ops-er-time">
                  {Math.floor((Date.now() - new Date(entry.registeredAt).getTime()) / 60000)}m ago
                </span>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ─── Exceptions Section ─── */

function ExceptionsSection({
  bedCap,
  wards,
  erQueue,
  navigate,
}: {
  bedCap: Record<string, unknown>;
  wards: WardInfo[];
  erQueue: unknown[];
  navigate: ReturnType<typeof useNavigate>;
}) {
  const exceptions: Array<{
    id: string;
    type: string;
    severity: 'high' | 'medium' | 'low';
    message: string;
    route: string;
  }> = [];

  // High occupancy alert
  const totalBeds = (bedCap.total as number) ?? 0;
  const occupied = (bedCap.occupied as number) ?? 0;
  if (totalBeds > 0 && (occupied / totalBeds) > 0.9) {
    exceptions.push({
      id: 'high-occupancy',
      type: 'capacity',
      severity: 'high',
      message: `Hospital occupancy at ${Math.round((occupied / totalBeds) * 100)}% — near full capacity`,
      route: '/beds',
    });
  }

  // ER queue backlog
  if (erQueue.length > 5) {
    exceptions.push({
      id: 'er-backlog',
      type: 'flow',
      severity: 'high',
      message: `${erQueue.length} patients waiting in ER queue`,
      route: '/emergency',
    });
  }

  // High-occupancy wards
  for (const ward of wards) {
    const total = Object.values(ward.counts).reduce((s, n) => s + n, 0);
    const wardOccupied = ward.counts.occupied ?? 0;
    if (total > 0 && (wardOccupied / total) > 0.9) {
      exceptions.push({
        id: `ward-${ward.id}`,
        type: 'capacity',
        severity: 'medium',
        message: `${ward.name} at ${Math.round((wardOccupied / total) * 100)}% capacity`,
        route: '/beds',
      });
    }
  }

  // Cleaning backlog
  const cleaningCount = wards.reduce((sum, w) => sum + (w.counts.cleaning ?? 0), 0);
  if (cleaningCount > 3) {
    exceptions.push({
      id: 'cleaning-backlog',
      type: 'resource',
      severity: 'medium',
      message: `${cleaningCount} beds in cleaning status`,
      route: '/beds',
    });
  }

  // Maintenance beds
  const maintenanceCount = wards.reduce((sum, w) => sum + (w.counts.maintenance ?? 0), 0);
  if (maintenanceCount > 0) {
    exceptions.push({
      id: 'maintenance',
      type: 'equipment',
      severity: 'low',
      message: `${maintenanceCount} beds under maintenance`,
      route: '/beds',
    });
  }

  if (exceptions.length === 0) {
    return (
      <div className="ops-section">
        <div className="ops-section-header">
          <h3>Exceptions</h3>
          <span className="ops-badge ops-badge--green">All Clear</span>
        </div>
        <Card className="ops-empty-card">
          <div className="ops-empty-icon">
            <Activity size={24} style={{ color: '#10b981' }} />
          </div>
          <p className="ops-empty-text">No operational exceptions detected</p>
          <p className="ops-empty-sub">All systems operating within normal parameters</p>
        </Card>
      </div>
    );
  }

  const severityColors = {
    high: { bg: '#fef2f2', border: '#fecaca', text: '#dc2626', icon: AlertTriangle },
    medium: { bg: '#fffbeb', border: '#fde68a', text: '#d97706', icon: AlertTriangle },
    low: { bg: '#f0f9ff', border: '#bae6fd', text: '#0284c7', icon: Clock },
  };

  return (
    <div className="ops-section">
      <div className="ops-section-header">
        <h3>Exceptions</h3>
        <span className="ops-badge ops-badge--alert">{exceptions.length} active</span>
      </div>
      <div className="ops-exceptions">
        {exceptions.map((ex) => {
          const sev = severityColors[ex.severity];
          const Icon = sev.icon;
          return (
            <div
              key={ex.id}
              className="ops-exception"
              style={{ background: sev.bg, border: `1px solid ${sev.border}` }}
              onClick={() => navigate(ex.route)}
            >
              <Icon size={18} style={{ color: sev.text, flexShrink: 0 }} />
              <div className="ops-exception-info">
                <span className="ops-exception-type" style={{ color: sev.text }}>{ex.type}</span>
                <span className="ops-exception-msg">{ex.message}</span>
              </div>
              <ArrowRight size={14} style={{ color: sev.text, flexShrink: 0 }} />
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ─── Quick Actions Section ─── */

function QuickActionsSection() {
  const navigate = useNavigate();
  const { hasAnyRole } = useAccess();

  const actions = useMemo(() => {
    const all = [
      { label: 'Bed Occupancy', icon: Bed, route: '/beds', roles: ['hospital_admin', 'org_admin', 'superadmin'] },
      { label: 'Patient Flow', icon: Activity, route: '/clinical/flow', roles: ['hospital_admin', 'org_admin', 'doctor', 'nurse', 'receptionist'] },
      { label: 'Queue', icon: Clock, route: '/clinical/queue', roles: ['hospital_admin', 'org_admin', 'doctor', 'nurse', 'receptionist'] },
      { label: 'IPD Dashboard', icon: Bed, route: '/ipd', roles: ['hospital_admin', 'org_admin', 'doctor', 'nurse'] },
      { label: 'ICU', icon: HeartPulse, route: '/icu', roles: ['hospital_admin', 'org_admin', 'doctor', 'nurse'] },
      { label: 'Operating Theatre', icon: Scissors, route: '/ot', roles: ['hospital_admin', 'org_admin', 'doctor', 'nurse'] },
      { label: 'Radiology', icon: ScanLine, route: '/radiology', roles: ['hospital_admin', 'org_admin', 'radiologist', 'radiographer'] },
      { label: 'Pharmacy', icon: Pill, route: '/pharmacy', roles: ['hospital_admin', 'org_admin', 'pharmacist'] },
      { label: 'Supply Chain', icon: Package, route: '/procurement', roles: ['hospital_admin', 'org_admin'] },
    ];
    return all.filter((a) => a.roles.some((r) => hasAnyRole(r as any)));
  }, [hasAnyRole]);

  return (
    <div className="ops-section">
      <div className="ops-section-header">
        <h3>Quick Actions</h3>
      </div>
      <div className="ops-actions-grid">
        {actions.map((action) => {
          const Icon = action.icon;
          return (
            <button
              key={action.label}
              className="ops-action-btn"
              onClick={() => navigate(action.route)}
            >
              <Icon size={18} />
              <span>{action.label}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}

/* ─── Main HospitalOpsCenter ─── */

export function HospitalOpsCenter() {
  const navigate = useNavigate();
  const { organizationId } = useTenant();

  const dash = useFetch(() => orchestrationApi.dashboard().catch(() => ({})), []);
  const cap = useFetch(() => orchestrationApi.capacity().catch(() => ({})), []);
  const flow = useFetch(() => orchestrationApi.patientFlow().catch(() => ({})), []);
  const occupancy = useFetch(
    () => organizationId ? bedWardApi.occupancy(organizationId) : Promise.resolve({ summary: {}, wards: [] }),
    [organizationId],
  );
  const erQueue = useFetch(() => erApi.queue().catch(() => []), []);

  const c = useMemo(() => (cap.data ?? {}) as Record<string, unknown>, [cap.data]);
  const f = useMemo(() => (flow.data ?? {}) as Record<string, unknown>, [flow.data]);
  const bedCap = useMemo(() => (c.beds ?? {}) as Record<string, unknown>, [c]);
  const appCap = useMemo(() => (c.appointments ?? {}) as Record<string, unknown>, [c]);
  const wards: WardInfo[] = useMemo(() => (occupancy.data?.wards ?? []) as WardInfo[], [occupancy.data]);
  const erQueueData = useMemo(() => (erQueue.data ?? []) as Array<{ encounterId: string; patientId: string; triageLevel: number | null; triageColor: string | null; presentingComplaint: string | null; registeredAt: string | null }>, [erQueue.data]);

  const refresh = useCallback(() => {
    dash.refresh();
    cap.refresh();
    flow.refresh();
    occupancy.refresh();
    erQueue.refresh();
  }, [dash, cap, flow, occupancy, erQueue]);

  const [showCommandCenter, setShowCommandCenter] = useState(true);

  return (
    <div className="page ops-page">
      <header className="ops-header">
        <div className="ops-header-title">
          <Activity size={24} />
          <div>
            <h1>Hospital Operations</h1>
            <p className="ops-subtitle">Capacity, flow, exceptions, and actions</p>
          </div>
        </div>
        <Button variant="ghost" size="sm" onClick={refresh}>
          <RefreshCw size={16} /> Refresh
        </Button>
      </header>

      {/* Command Center toggle */}
      <div className="ops-command-toggle">
        <Button
          variant={showCommandCenter ? 'primary' : 'ghost'}
          size="sm"
          onClick={() => setShowCommandCenter(!showCommandCenter)}
        >
          <Activity size={14} />
          {showCommandCenter ? 'Hide Command Center' : 'Show Command Center'}
        </Button>
      </div>

      {/* Hospital Command Center */}
      {showCommandCenter && <HospitalCommandCenter />}

      {/* Capacity Overview */}
      <CapacityOverview
        bedCap={bedCap}
        appointments={appCap}
        flow={f}
        navigate={navigate}
      />

      {/* Two-column layout */}
      <div className="ops-columns">
        {/* Left column: Ward Capacity + ER Queue */}
        <div className="ops-col-main">
          <WardOverview wards={wards} />
          <ERQueueSection erQueue={erQueueData} />
        </div>

        {/* Right column: Flow + Exceptions */}
        <div className="ops-col-side">
          <PatientFlowSection flow={f} />
          <ExceptionsSection
            bedCap={bedCap}
            wards={wards}
            erQueue={erQueueData}
            navigate={navigate}
          />
          <QuickActionsSection />
        </div>
      </div>
    </div>
  );
}

export default HospitalOpsCenter;
