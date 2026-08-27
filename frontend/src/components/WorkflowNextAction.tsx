/**
 * WorkflowNextAction — Natural Next Step Guidance (Phase 126)
 *
 * Shows the natural next clinical step based on the current workspace,
 * pending work, and clinical context.
 *
 * Answers: "WHAT HAPPENS NEXT?"
 *
 * Not a dashboard. Not a module menu.
 * A compact forward-navigation hint that helps the clinician
 * continue the workflow without returning to global navigation.
 *
 * Safety:
 * - Navigation actions only
 * - No auto-mutations
 * - No clinical decisions
 * - Backend authorization remains authoritative
 * - Patient context preserved
 */

import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  ArrowRight,
  FlaskConical,
  Pill,
  Stethoscope,
  ClipboardList,
  Clock,
  FileText,
  AlertTriangle,
} from 'lucide-react';
import './workflow-next-action.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

interface NextAction {
  id: string;
  label: string;
  description: string;
  icon: React.ReactNode;
  route: string;
  urgency: 'routine' | 'attention' | 'urgent' | 'critical';
}

/* ────────────────────────────────────────────────────────────────────
   DERIVE NEXT ACTIONS
   ──────────────────────────────────────────────────────────────────── */

function deriveNextActions(
  currentWorkspace: string,
  patientId: string,
  pendingLabs: number,
  criticalItems: number,
  activePrescriptions: number,
  activeEncounters: number,
  isAdmitted: boolean,
  activeDiagnoses: number,
): NextAction[] {
  const actions: NextAction[] = [];

  // Context-aware next actions based on current workspace
  switch (currentWorkspace) {
    case 'overview':
      // From overview, suggest the most relevant next workspace
      if (criticalItems > 0) {
        actions.push({
          id: 'review-critical',
          label: 'Review Critical Results',
          description: `${criticalItems} critical result${criticalItems > 1 ? 's' : ''} require immediate attention`,
          icon: <AlertTriangle size={14} />,
          route: `/clinical/patients/${patientId}?ws=lab`,
          urgency: 'critical',
        });
      } else if (pendingLabs > 0) {
        actions.push({
          id: 'review-labs',
          label: 'Review Lab Results',
          description: `${pendingLabs} result${pendingLabs > 1 ? 's' : ''} awaiting review`,
          icon: <FlaskConical size={14} />,
          route: `/clinical/patients/${patientId}?ws=lab`,
          urgency: 'attention',
        });
      }
      if (activeEncounters > 0) {
        actions.push({
          id: 'continue-encounter',
          label: 'Continue Encounter',
          description: `${activeEncounters} active encounter${activeEncounters > 1 ? 's' : ''} requiring documentation`,
          icon: <Stethoscope size={14} />,
          route: `/clinical/patients/${patientId}?ws=encounters`,
          urgency: 'attention',
        });
      }
      if (activePrescriptions > 0) {
        actions.push({
          id: 'review-meds',
          label: 'Review Medications',
          description: `${activePrescriptions} active prescription${activePrescriptions > 1 ? 's' : ''}`,
          icon: <Pill size={14} />,
          route: `/clinical/patients/${patientId}?ws=medications`,
          urgency: 'routine',
        });
      }
      break;

    case 'encounters':
      // After encounters, suggest diagnoses or orders
      if (activeDiagnoses === 0) {
        actions.push({
          id: 'add-diagnosis',
          label: 'Add Diagnosis',
          description: 'Record clinical findings from encounter',
          icon: <ClipboardList size={14} />,
          route: `/clinical/forms?patientId=${patientId}&type=diagnosis`,
          urgency: 'routine',
        });
      }
      actions.push({
        id: 'order-lab',
        label: 'Order Lab',
        description: 'Place laboratory order based on encounter',
        icon: <FlaskConical size={14} />,
        route: `/clinical/forms?patientId=${patientId}&type=lab`,
        urgency: 'routine',
      });
      break;

    case 'diagnoses':
      // After diagnoses, suggest orders or prescriptions
      actions.push({
        id: 'order-lab',
        label: 'Order Lab',
        description: 'Order labs to confirm or monitor diagnosis',
        icon: <FlaskConical size={14} />,
        route: `/clinical/forms?patientId=${patientId}&type=lab`,
        urgency: 'routine',
      });
      actions.push({
        id: 'prescribe',
        label: 'Prescribe',
        description: 'Create prescription for active diagnosis',
        icon: <Pill size={14} />,
        route: `/clinical/forms?patientId=${patientId}&type=prescription`,
        urgency: 'routine',
      });
      break;

    case 'lab':
      // After reviewing labs, suggest clinical actions
      if (criticalItems > 0) {
        actions.push({
          id: 'add-note',
          label: 'Document Finding',
          description: 'Record critical result in clinical note',
          icon: <FileText size={14} />,
          route: `/clinical/forms?patientId=${patientId}&type=note`,
          urgency: 'critical',
        });
      }
      actions.push({
        id: 'timeline',
        label: 'View Timeline',
        description: 'See chronological clinical history',
        icon: <Clock size={14} />,
        route: `/clinical/patients/${patientId}?ws=timeline`,
        urgency: 'routine',
      });
      break;

    case 'medications':
      // After medications, suggest pharmacy or timeline
      actions.push({
        id: 'timeline',
        label: 'View Timeline',
        description: 'See medication history and changes',
        icon: <Clock size={14} />,
        route: `/clinical/patients/${patientId}?ws=timeline`,
        urgency: 'routine',
      });
      break;

    case 'admissions':
      // After admissions, suggest nursing or timeline
      if (isAdmitted) {
        actions.push({
          id: 'nursing',
          label: 'Nursing Tasks',
          description: 'Record vitals and nursing observations',
          icon: <ClipboardList size={14} />,
          route: `/nursing?patientId=${patientId}`,
          urgency: 'attention',
        });
      }
      break;

    default:
      // Generic next actions
      actions.push({
        id: 'overview',
        label: 'Back to Overview',
        description: 'Return to patient overview',
        icon: <ArrowRight size={14} />,
        route: `/clinical/patients/${patientId}?ws=overview`,
        urgency: 'routine',
      });
      break;
  }

  // Always offer timeline as a fallback
  if (currentWorkspace !== 'timeline' && actions.length < 2) {
    actions.push({
      id: 'timeline',
      label: 'Timeline',
      description: 'View clinical history',
      icon: <Clock size={14} />,
      route: `/clinical/patients/${patientId}?ws=timeline`,
      urgency: 'routine',
    });
  }

  return actions.slice(0, 3); // Max 3 next actions
}

/* ────────────────────────────────────────────────────────────────────
   URGENCY STYLING
   ──────────────────────────────────────────────────────────────────── */

const URGENCY_STYLE: Record<string, { color: string; bg: string; border: string }> = {
  routine: { color: 'var(--text-secondary)', bg: 'var(--white)', border: 'var(--border-subtle)' },
  attention: { color: 'var(--blue-700)', bg: 'var(--blue-50)', border: 'var(--blue-200)' },
  urgent: { color: 'var(--amber-700)', bg: 'var(--amber-50)', border: 'var(--amber-200)' },
  critical: { color: 'var(--red-700)', bg: 'var(--red-50)', border: 'var(--red-200)' },
};

/* ────────────────────────────────────────────────────────────────────
   MAIN WORKFLOW NEXT ACTION
   ──────────────────────────────────────────────────────────────────── */

interface WorkflowNextActionProps {
  currentWorkspace: string;
  patientId: string;
  pendingLabs?: number;
  criticalItems?: number;
  activePrescriptions?: number;
  activeEncounters?: number;
  isAdmitted?: boolean;
  activeDiagnoses?: number;
}

export function WorkflowNextAction({
  currentWorkspace,
  patientId,
  pendingLabs = 0,
  criticalItems = 0,
  activePrescriptions = 0,
  activeEncounters = 0,
  isAdmitted = false,
  activeDiagnoses = 0,
}: WorkflowNextActionProps) {
  const navigate = useNavigate();

  const nextActions = useMemo(
    () => deriveNextActions(
      currentWorkspace, patientId, pendingLabs, criticalItems,
      activePrescriptions, activeEncounters, isAdmitted, activeDiagnoses,
    ),
    [currentWorkspace, patientId, pendingLabs, criticalItems,
     activePrescriptions, activeEncounters, isAdmitted, activeDiagnoses],
  );

  if (nextActions.length === 0) return null;

  return (
    <div className="wna" role="region" aria-label="Suggested next actions">
      <span className="wna__label">Next</span>
      <div className="wna__actions">
        {nextActions.map((action) => {
          const style = URGENCY_STYLE[action.urgency];
          return (
            <button
              key={action.id}
              type="button"
              className={`wna__action ${action.urgency !== 'routine' ? `wna__action--${action.urgency}` : ''}`}
              style={{ borderColor: style.border, background: style.bg }}
              onClick={() => navigate(action.route)}
              title={action.description}
              aria-label={`${action.label}: ${action.description}`}
            >
              <span className="wna__action-icon" style={{ color: style.color }}>{action.icon}</span>
              <span className="wna__action-label" style={{ color: style.color }}>{action.label}</span>
              <ArrowRight size={10} className="wna__action-arrow" style={{ color: style.color }} />
            </button>
          );
        })}
      </div>
    </div>
  );
}

export default WorkflowNextAction;
