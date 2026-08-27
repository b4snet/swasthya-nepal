/**
 * ClinicalCommandSurface — Unified Clinical Command Surface (Phase 125)
 *
 * A contextual action surface that resolves available actions based on:
 * - current patient
 * - current encounter
 * - current department
 * - current role
 * - current permissions
 * - current clinical state
 *
 * NOT a dashboard. NOT a module directory. NOT a generic card grid.
 * A compact, intention-driven action surface that answers:
 *   "What can I meaningfully do here, right now?"
 *
 * Action taxonomy:
 *   Primary    — the most important current action
 *   Clinical   — patient-care actions
 *   Review     — information-review actions
 *   Operational — workflow actions
 *   Destructive — actions requiring deliberate confirmation
 *
 * Safety:
 * - Frontend action visibility is UX optimization only
 * - Backend authorization remains authoritative
 * - No clinical decisions
 * - No auto-mutations
 * - Destructive actions require confirmation
 * - Patient context integrity preserved
 */

import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import {
  Stethoscope,
  Pill,
  FlaskConical,
  ClipboardList,
  Bed,
  Clock,
  FileText,
  Activity,
  ChevronUp,
  Plus,
  Eye,
  MoreHorizontal,
} from 'lucide-react';
import './clinical-command-surface.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

type ActionCategory = 'primary' | 'clinical' | 'review' | 'operational' | 'destructive';
type ActionUrgency = 'routine' | 'attention' | 'urgent' | 'critical';

interface CommandAction {
  id: string;
  label: string;
  description: string;
  icon: React.ReactNode;
  route: string;
  category: ActionCategory;
  urgency: ActionUrgency;
  /** Roles that can see this action */
  roles: string[];
  /** Whether this is a mutation vs navigation */
  isMutation: boolean;
  /** Whether this requires a patient context */
  requiresPatient: boolean;
}

/* ────────────────────────────────────────────────────────────────────
   ROLE CONSTANTS
   ──────────────────────────────────────────────────────────────────── */

const CLINICAL_ROLES = ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'];
const DOCTOR_ROLES = ['doctor', 'hospital_admin', 'org_admin', 'superadmin'];
const NURSE_ROLES = ['nurse', 'hospital_admin', 'org_admin', 'superadmin'];


/* ────────────────────────────────────────────────────────────────────
   ACTION REGISTRY
   ──────────────────────────────────────────────────────────────────── */

function buildActions(patientId: string): CommandAction[] {
  return [
    // ── Primary clinical actions ──
    {
      id: 'new-encounter',
      label: 'New Encounter',
      description: 'Start a clinical encounter',
      icon: <Plus size={14} />,
      route: `/clinical/encounters?patientId=${patientId}`,
      category: 'primary',
      urgency: 'routine',
      roles: DOCTOR_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
    {
      id: 'order-lab',
      label: 'Order Lab',
      description: 'Place a laboratory order',
      icon: <FlaskConical size={14} />,
      route: `/clinical/forms?patientId=${patientId}&type=lab`,
      category: 'primary',
      urgency: 'routine',
      roles: DOCTOR_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
    {
      id: 'prescribe',
      label: 'Prescribe',
      description: 'Create a prescription',
      icon: <Pill size={14} />,
      route: `/clinical/forms?patientId=${patientId}&type=prescription`,
      category: 'primary',
      urgency: 'routine',
      roles: DOCTOR_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
    {
      id: 'add-diagnosis',
      label: 'Add Diagnosis',
      description: 'Record a diagnosis or problem',
      icon: <ClipboardList size={14} />,
      route: `/clinical/forms?patientId=${patientId}&type=diagnosis`,
      category: 'primary',
      urgency: 'routine',
      roles: DOCTOR_ROLES,
      isMutation: false,
      requiresPatient: true,
    },

    // ── Clinical review actions ──
    {
      id: 'review-results',
      label: 'Review Results',
      description: 'View pending lab results',
      icon: <Eye size={14} />,
      route: `/clinical/patients/${patientId}?ws=lab`,
      category: 'review',
      urgency: 'attention',
      roles: CLINICAL_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
    {
      id: 'view-timeline',
      label: 'Timeline',
      description: 'View clinical history',
      icon: <Clock size={14} />,
      route: `/clinical/patients/${patientId}?ws=timeline`,
      category: 'review',
      urgency: 'routine',
      roles: CLINICAL_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
    {
      id: 'view-medications',
      label: 'Medications',
      description: 'View active prescriptions',
      icon: <Pill size={14} />,
      route: `/clinical/patients/${patientId}?ws=medications`,
      category: 'review',
      urgency: 'routine',
      roles: CLINICAL_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
    {
      id: 'view-encounters',
      label: 'Encounters',
      description: 'View clinical visit records',
      icon: <Stethoscope size={14} />,
      route: `/clinical/patients/${patientId}?ws=encounters`,
      category: 'review',
      urgency: 'routine',
      roles: CLINICAL_ROLES,
      isMutation: false,
      requiresPatient: true,
    },

    // ── Operational actions ──
    {
      id: 'refer',
      label: 'Refer',
      description: 'Create a patient referral',
      icon: <FileText size={14} />,
      route: `/clinical/referrals?patientId=${patientId}`,
      category: 'operational',
      urgency: 'routine',
      roles: DOCTOR_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
    {
      id: 'admit',
      label: 'Admit',
      description: 'Start inpatient admission',
      icon: <Bed size={14} />,
      route: `/clinical/forms?patientId=${patientId}&type=admission`,
      category: 'operational',
      urgency: 'routine',
      roles: DOCTOR_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
    {
      id: 'nursing-task',
      label: 'Nursing Task',
      description: 'Record nursing task or vitals',
      icon: <Activity size={14} />,
      route: `/nursing?patientId=${patientId}`,
      category: 'operational',
      urgency: 'routine',
      roles: NURSE_ROLES,
      isMutation: false,
      requiresPatient: true,
    },
  ];
}

/* ────────────────────────────────────────────────────────────────────
   CATEGORY CONFIG
   ──────────────────────────────────────────────────────────────────── */



/* ────────────────────────────────────────────────────────────────────
   URGENCY STYLING
   ──────────────────────────────────────────────────────────────────── */

const URGENCY_STYLE: Record<ActionUrgency, { color: string; bg: string; border: string }> = {
  routine: { color: 'var(--text-secondary)', bg: 'var(--white)', border: 'var(--border-subtle)' },
  attention: { color: 'var(--blue-700)', bg: 'var(--blue-50)', border: 'var(--blue-200)' },
  urgent: { color: 'var(--amber-700)', bg: 'var(--amber-50)', border: 'var(--amber-200)' },
  critical: { color: 'var(--red-700)', bg: 'var(--red-50)', border: 'var(--red-200)' },
};

/* ────────────────────────────────────────────────────────────────────
   MAIN CLINICAL COMMAND SURFACE
   ──────────────────────────────────────────────────────────────────── */

interface ClinicalCommandSurfaceProps {
  patientId: string;
  /** Active clinical context for urgency adjustment */
  pendingLabs?: number;
  criticalItems?: number;
  activeEncounters?: number;
}

export function ClinicalCommandSurface({
  patientId,
  pendingLabs = 0,
  criticalItems = 0,
  activeEncounters = 0,
}: ClinicalCommandSurfaceProps) {
  const navigate = useNavigate();
  const { hasRole } = useTenant();
  const [expanded, setExpanded] = useState(false);

  // Build and filter actions by role
  const allActions = useMemo(() => {
    const actions = buildActions(patientId);
    return actions.filter((a) => {
      if (a.roles.length === 0) return true;
      return a.roles.some((r) => hasRole(r as any));
    });
  }, [patientId, hasRole]);



  // Adjust urgency based on clinical context
  const adjustedActions = useMemo(() => {
    return allActions.map((action) => {
      let urgency = action.urgency;
      let reason = '';

      // Pending labs elevate review actions
      if (action.id === 'review-results' && pendingLabs > 0) {
        urgency = criticalItems > 0 ? 'critical' : 'attention';
        reason = criticalItems > 0
          ? `${criticalItems} critical result${criticalItems > 1 ? 's' : ''} pending`
          : `${pendingLabs} result${pendingLabs > 1 ? 's' : ''} awaiting review`;
      }

      // Active encounters elevate encounter-related actions
      if (action.id === 'view-encounters' && activeEncounters > 0) {
        urgency = 'attention';
        reason = `${activeEncounters} active encounter${activeEncounters > 1 ? 's' : ''}`;
      }

      return { ...action, urgency, reason };
    });
  }, [allActions, pendingLabs, criticalItems, activeEncounters]);

  // Primary actions (always visible)
  const primaryActions = adjustedActions.filter((a) => a.category === 'primary');
  // Secondary actions (visible when expanded or always for review)
  const reviewActions = adjustedActions.filter((a) => a.category === 'review');
  const operationalActions = adjustedActions.filter((a) => a.category === 'operational');
  const hiddenCount = operationalActions.length;

  if (!patientId || allActions.length === 0) return null;

  return (
    <div className="ccs" role="region" aria-label="Clinical command surface">
      {/* Primary actions — always visible */}
      <div className="ccs__primary">
        {primaryActions.map((action) => {
          const style = URGENCY_STYLE[action.urgency];
          return (
            <button
              key={action.id}
              type="button"
              className="ccs__action ccs__action--primary"
              style={{ borderColor: style.border, background: style.bg }}
              onClick={() => navigate(action.route)}
              title={action.description}
              aria-label={`${action.label}: ${action.description}`}
              data-testid={`ccs-${action.id}`}
            >
              <span className="ccs__action-icon" style={{ color: style.color }}>{action.icon}</span>
              <span className="ccs__action-label" style={{ color: style.color }}>{action.label}</span>
            </button>
          );
        })}
      </div>

      {/* Review actions — always visible */}
      <div className="ccs__review">
        {reviewActions.map((action) => {
          const style = URGENCY_STYLE[action.urgency];
          return (
            <button
              key={action.id}
              type="button"
              className={`ccs__action ccs__action--review ${action.urgency !== 'routine' ? `ccs__action--${action.urgency}` : ''}`}
              style={{ borderColor: style.border, background: style.bg }}
              onClick={() => navigate(action.route)}
              title={action.description}
              aria-label={`${action.label}: ${action.description}`}
              data-testid={`ccs-${action.id}`}
            >
              <span className="ccs__action-icon" style={{ color: style.color }}>{action.icon}</span>
              <span className="ccs__action-label" style={{ color: style.color }}>{action.label}</span>
              {action.urgency === 'critical' && (
                <span className="ccs__action-badge ccs__action-badge--critical">!</span>
              )}
            </button>
          );
        })}
      </div>

      {/* More actions — expandable */}
      {operationalActions.length > 0 && (
        <>
          <button
            type="button"
            className="ccs__more"
            onClick={() => setExpanded((v) => !v)}
            aria-expanded={expanded}
            aria-label={`${expanded ? 'Hide' : 'Show'} ${operationalActions.length} more actions`}
          >
            {expanded ? <ChevronUp size={12} /> : <MoreHorizontal size={12} />}
            <span>{expanded ? 'Less' : `${hiddenCount} more`}</span>
          </button>

          {expanded && (
            <div className="ccs__expanded">
              {operationalActions.map((action) => {
                const style = URGENCY_STYLE[action.urgency];
                return (
                  <button
                    key={action.id}
                    type="button"
                    className="ccs__action ccs__action--operational"
                    style={{ borderColor: style.border, background: style.bg }}
                    onClick={() => navigate(action.route)}
                    title={action.description}
                    aria-label={`${action.label}: ${action.description}`}
                    data-testid={`ccs-${action.id}`}
                  >
                    <span className="ccs__action-icon" style={{ color: style.color }}>{action.icon}</span>
                    <span className="ccs__action-label" style={{ color: style.color }}>{action.label}</span>
                  </button>
                );
              })}
            </div>
          )}
        </>
      )}
    </div>
  );
}

export default ClinicalCommandSurface;
