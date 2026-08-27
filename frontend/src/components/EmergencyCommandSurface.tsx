/**
 * EmergencyCommandSurface — Emergency Clinical Command Surface (Phase 123)
 *
 * A dedicated command surface for the Emergency department.
 * Replaces the generic page pattern with a clinical command experience.
 *
 * When Emergency is activated:
 * - Emergency-specific actions revealed
 * - Compact visual action objects (icon + label)
 * - Urgency immediately understandable
 * - Navigation depth minimized
 * - Patient context preserved
 * - Destructive actions prevented without confirmation
 *
 * NOT a generic dashboard. NOT a conventional page.
 * A clinical command surface for acute care.
 *
 * Safety:
 * - All actions route to existing authorized workspaces
 * - No auto-mutations
 * - No clinical decisions
 * - Backend authorization remains authoritative
 * - Wrong-patient defense via patient context strip
 */

import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useClinicalContext } from '../context/ClinicalContext';
import {
  Siren,
  Activity,
  HeartPulse,
  AlertTriangle,
  ClipboardList,
  Stethoscope,
  ListOrdered,
  Bed,
  ArrowRight,
  Clock,
  Users,
  FlaskConical,
} from 'lucide-react';
import './emergency-command-surface.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

interface EmergencyAction {
  id: string;
  label: string;
  description: string;
  icon: React.ReactNode;
  route: string;
  urgency: 'routine' | 'attention' | 'urgent' | 'critical';
  roles: string[];
}

/* ────────────────────────────────────────────────────────────────────
   EMERGENCY ACTIONS
   ──────────────────────────────────────────────────────────────────── */

const EMERGENCY_ACTIONS: EmergencyAction[] = [
  {
    id: 'triage',
    label: 'Triage',
    description: 'Assess and prioritize incoming patients',
    icon: <Siren size={20} />,
    route: '/emergency',
    urgency: 'critical',
    roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'],
  },
  {
    id: 'active-cases',
    label: 'Active Cases',
    description: 'Currently treating emergency patients',
    icon: <Activity size={20} />,
    route: '/emergency',
    urgency: 'urgent',
    roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'],
  },
  {
    id: 'critical-alerts',
    label: 'Critical Alerts',
    description: 'Unacknowledged critical values and alerts',
    icon: <AlertTriangle size={20} />,
    route: '/laboratory/critical-values',
    urgency: 'critical',
    roles: ['doctor', 'nurse', 'lab_technician', 'lab_supervisor', 'hospital_admin'],
  },
  {
    id: 'resuscitation',
    label: 'Resuscitation',
    description: 'Active resuscitation cases',
    icon: <HeartPulse size={20} />,
    route: '/emergency',
    urgency: 'critical',
    roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'],
  },
  {
    id: 'emergency-queue',
    label: 'Waiting',
    description: 'Patients awaiting emergency assessment',
    icon: <ListOrdered size={20} />,
    route: '/clinical/queue',
    urgency: 'urgent',
    roles: ['doctor', 'nurse', 'receptionist', 'hospital_admin'],
  },
  {
    id: 'orders',
    label: 'Orders',
    description: 'Emergency orders and forms',
    icon: <ClipboardList size={20} />,
    route: '/clinical/forms',
    urgency: 'routine',
    roles: ['doctor', 'nurse', 'hospital_admin'],
  },
  {
    id: 'bed-availability',
    label: 'Beds',
    description: 'Emergency bed availability',
    icon: <Bed size={20} />,
    route: '/beds',
    urgency: 'attention',
    roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin'],
  },
  {
    id: 'labs',
    label: 'Lab Results',
    description: 'Pending and critical lab results',
    icon: <FlaskConical size={20} />,
    route: '/laboratory/reports',
    urgency: 'attention',
    roles: ['doctor', 'nurse', 'lab_technician'],
  },
  {
    id: 'transfers',
    label: 'Transfers',
    description: 'Patient transfers and dispositions',
    icon: <ArrowRight size={20} />,
    route: '/emergency',
    urgency: 'routine',
    roles: ['doctor', 'nurse', 'hospital_admin'],
  },
  {
    id: 'patients',
    label: 'Patients',
    description: 'Emergency patient records',
    icon: <Users size={20} />,
    route: '/clinical/patients',
    urgency: 'routine',
    roles: ['doctor', 'nurse', 'receptionist', 'hospital_admin'],
  },
];

/* ────────────────────────────────────────────────────────────────────
   URGENCY CONFIG
   ──────────────────────────────────────────────────────────────────── */

const URGENCY_CONFIG: Record<string, { color: string; bg: string; border: string; label: string }> = {
  routine: { color: 'var(--text-secondary)', bg: 'var(--white)', border: 'var(--border-subtle)', label: '' },
  attention: { color: 'var(--blue-700)', bg: 'var(--blue-50)', border: 'var(--blue-200)', label: 'Attention' },
  urgent: { color: 'var(--amber-700)', bg: 'var(--amber-50)', border: 'var(--amber-300)', label: 'Urgent' },
  critical: { color: 'var(--red-700)', bg: 'var(--red-50)', border: 'var(--red-300)', label: 'Critical' },
};

/* ────────────────────────────────────────────────────────────────────
   ACTION CARD
   ──────────────────────────────────────────────────────────────────── */

function EmergencyActionCard({ action }: { action: EmergencyAction }) {
  const navigate = useNavigate();
  const config = URGENCY_CONFIG[action.urgency];

  return (
    <button
      type="button"
      className={`ecs-card ecs-card--${action.urgency}`}
      style={{ borderColor: config.border, background: config.bg }}
      onClick={() => navigate(action.route)}
      aria-label={`${action.label}: ${action.description}`}
      data-testid={`ecs-${action.id}`}
    >
      <div className="ecs-card__icon" style={{ color: config.color }}>
        {action.icon}
      </div>
      <div className="ecs-card__body">
        <span className="ecs-card__label" style={{ color: config.color }}>{action.label}</span>
        <span className="ecs-card__desc">{action.description}</span>
      </div>
      {action.urgency === 'critical' && (
        <span className="ecs-card__badge ecs-card__badge--critical">!</span>
      )}
    </button>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN EMERGENCY COMMAND SURFACE
   ──────────────────────────────────────────────────────────────────── */

export function EmergencyCommandSurface() {
  const { hasRole } = useTenant();
  const clinicalCtx = useClinicalContext();

  // Filter actions by role
  const visibleActions = EMERGENCY_ACTIONS.filter(
    (a) => a.roles.length === 0 || a.roles.some((r) => hasRole(r as any)),
  );

  // Separate by urgency
  const criticalActions = visibleActions.filter((a) => a.urgency === 'critical');
  const urgentActions = visibleActions.filter((a) => a.urgency === 'urgent');
  const attentionActions = visibleActions.filter((a) => a.urgency === 'attention');
  const routineActions = visibleActions.filter((a) => a.urgency === 'routine');

  return (
    <div className="emergency-command-surface" role="region" aria-label="Emergency command surface">
      {/* Emergency header */}
      <div className="ecs-header">
        <div className="ecs-header__icon">
          <Siren size={22} strokeWidth={2} />
        </div>
        <div className="ecs-header__info">
          <h2 className="ecs-header__title">Emergency</h2>
          <p className="ecs-header__subtitle">
            {clinicalCtx.encounterSetting === 'emergency'
              ? 'Emergency encounter active'
              : 'Emergency department command surface'}
          </p>
        </div>
        <div className="ecs-header__status">
          <Clock size={14} />
          <span>{new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
        </div>
      </div>

      {/* Critical actions — always visible first */}
      {criticalActions.length > 0 && (
        <div className="ecs-section" role="region" aria-label="Critical actions">
          <h3 className="ecs-section__title ecs-section__title--critical">
            <AlertTriangle size={14} />
            Requires Immediate Attention
          </h3>
          <div className="ecs-section__grid">
            {criticalActions.map((action) => (
              <EmergencyActionCard key={action.id} action={action} />
            ))}
          </div>
        </div>
      )}

      {/* Urgent actions */}
      {urgentActions.length > 0 && (
        <div className="ecs-section" role="region" aria-label="Urgent actions">
          <h3 className="ecs-section__title ecs-section__title--urgent">
            <Activity size={14} />
            Urgent
          </h3>
          <div className="ecs-section__grid">
            {urgentActions.map((action) => (
              <EmergencyActionCard key={action.id} action={action} />
            ))}
          </div>
        </div>
      )}

      {/* Attention actions */}
      {attentionActions.length > 0 && (
        <div className="ecs-section" role="region" aria-label="Attention actions">
          <h3 className="ecs-section__title">
            <ClipboardList size={14} />
            Attention
          </h3>
          <div className="ecs-section__grid">
            {attentionActions.map((action) => (
              <EmergencyActionCard key={action.id} action={action} />
            ))}
          </div>
        </div>
      )}

      {/* Routine actions */}
      {routineActions.length > 0 && (
        <div className="ecs-section" role="region" aria-label="Routine actions">
          <h3 className="ecs-section__title">
            <Stethoscope size={14} />
            Actions
          </h3>
          <div className="ecs-section__grid">
            {routineActions.map((action) => (
              <EmergencyActionCard key={action.id} action={action} />
            ))}
          </div>
        </div>
      )}

      {/* Safety notice */}
      <div className="ecs-notice" role="note">
        <AlertTriangle size={12} />
        <span>Emergency actions route to authorized workspaces. Backend authorization remains authoritative.</span>
      </div>
    </div>
  );
}

export default EmergencyCommandSurface;
