/**
 * WorkflowTrail — Clinical Workflow Continuity (Phase 120)
 *
 * A compact breadcrumb that communicates:
 * - patient identity
 * - encounter context
 * - current workspace
 * - previous context
 *
 * NOT a decorative breadcrumb tree.
 * NOT a navigation system.
 * A contextual orientation layer.
 *
 * Safety:
 * - Patient identity always visible
 * - Encounter context always accurate
 * - Return paths are deterministic
 * - No stale context
 */

import { useNavigate } from 'react-router-dom';
import {
  ChevronRight,
  ArrowLeft,
  Stethoscope,
  Activity,
} from 'lucide-react';
import './workflow-trail.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

interface TrailSegment {
  label: string;
  /** Route to navigate to when clicked */
  to?: string;
  /** Icon for the segment */
  icon?: React.ReactNode;
  /** Is this the current segment */
  isCurrent?: boolean;
  /** Urgency of this segment */
  urgency?: 'routine' | 'attention' | 'urgent' | 'critical';
}

interface WorkflowTrailProps {
  /** Patient name */
  patientName: string;
  /** Patient MRN */
  patientMrn?: string;
  /** Active encounter type (OPD, IPD, Emergency, etc.) */
  encounterType?: string;
  /** Current workspace label */
  currentWorkspace: string;
  /** Route to current workspace */
  currentRoute?: string;
  /** Previous workspace label */
  previousWorkspace?: string;
  /** Route to previous workspace */
  previousRoute?: string;
  /** Patient ID for navigation */
  patientId: string;
  /** Overall urgency */
  urgency?: 'routine' | 'attention' | 'urgent' | 'critical';
  /** What the clinician is currently doing */
  currentActivity?: string;
}

/* ────────────────────────────────────────────────────────────────────
   URGENCY CONFIG
   ──────────────────────────────────────────────────────────────────── */

const URGENCY_CONFIG: Record<string, { color: string; bg: string; label: string }> = {
  routine: { color: 'var(--text-secondary)', bg: 'transparent', label: '' },
  attention: { color: 'var(--blue-700)', bg: 'var(--blue-50)', label: 'Attention' },
  urgent: { color: 'var(--amber-700)', bg: 'var(--amber-50)', label: 'Urgent' },
  critical: { color: 'var(--red-700)', bg: 'var(--red-50)', label: 'Critical' },
};

/* ────────────────────────────────────────────────────────────────────
   MAIN WORKFLOW TRAIL
   ──────────────────────────────────────────────────────────────────── */

export function WorkflowTrail({
  patientName,
  patientMrn,
  encounterType,
  currentWorkspace,
  currentRoute,
  previousWorkspace,
  previousRoute,
  patientId,
  urgency = 'routine',
  currentActivity,
}: WorkflowTrailProps) {
  const navigate = useNavigate();
  const urgencyStyle = URGENCY_CONFIG[urgency] || URGENCY_CONFIG.routine;

  // Build trail segments
  const segments: TrailSegment[] = [
    {
      label: patientName,
      to: `/clinical/patients/${patientId}?ws=overview`,
      icon: <Activity size={12} />,
      isCurrent: false,
    },
  ];

  if (encounterType) {
    segments.push({
      label: encounterType,
      icon: <Stethoscope size={12} />,
      isCurrent: false,
    });
  }

  if (previousWorkspace && previousWorkspace !== currentWorkspace) {
    segments.push({
      label: previousWorkspace,
      to: previousRoute,
      isCurrent: false,
    });
  }

  segments.push({
    label: currentWorkspace,
    to: currentRoute,
    icon: undefined,
    isCurrent: true,
  });

  return (
    <div className="wt" role="navigation" aria-label="Clinical workflow trail">
      {/* Back button */}
      {previousRoute && (
        <button
          type="button"
          className="wt__back"
          onClick={() => navigate(previousRoute)}
          aria-label={`Back to ${previousWorkspace || 'previous'}`}
        >
          <ArrowLeft size={14} />
          <span className="wt__back-label">{previousWorkspace || 'Back'}</span>
        </button>
      )}

      {/* Trail segments */}
      <div className="wt__segments">
        {segments.map((segment, i) => (
          <span key={i} className="wt__segment-wrap">
            {i > 0 && <ChevronRight size={10} className="wt__sep" />}
            {segment.to && !segment.isCurrent ? (
              <button
                type="button"
                className="wt__segment wt__segment--link"
                onClick={() => navigate(segment.to!)}
              >
                {segment.icon && <span className="wt__segment-icon">{segment.icon}</span>}
                <span className="wt__segment-label">{segment.label}</span>
              </button>
            ) : (
              <span
                className={`wt__segment ${segment.isCurrent ? 'wt__segment--current' : ''}`}
                aria-current={segment.isCurrent ? 'page' : undefined}
              >
                {segment.icon && <span className="wt__segment-icon">{segment.icon}</span>}
                <span className="wt__segment-label">{segment.label}</span>
              </span>
            )}
          </span>
        ))}
      </div>

      {/* Current activity */}
      {currentActivity && (
        <span className="wt__activity" style={{ color: urgencyStyle.color }}>
          {currentActivity}
        </span>
      )}

      {/* Urgency indicator */}
      {urgency !== 'routine' && (
        <span
          className="wt__urgency"
          style={{ color: urgencyStyle.color, background: urgencyStyle.bg }}
        >
          {urgencyStyle.label}
        </span>
      )}

      {/* Patient MRN (compact) */}
      {patientMrn && (
        <span className="wt__mrn mono">{patientMrn}</span>
      )}
    </div>
  );
}

export default WorkflowTrail;
