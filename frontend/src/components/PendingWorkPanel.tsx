/**
 * PendingWorkPanel — Pending Clinical Work (Phase 123)
 *
 * A compact panel showing pending clinical work items that require
 * the clinician's attention for the current patient.
 *
 * Answers: "WHAT NEEDS MY ATTENTION FOR THIS PATIENT?"
 *
 * Sources (all existing APIs):
 * - Pending lab orders (not reported/verified)
 * - Active prescriptions (need monitoring)
 * - Open encounters (need documentation)
 * - Critical items (unacknowledged)
 * - Pending tasks (open loops)
 *
 * NOT a task database. NOT AI-prioritized.
 * Derived from canonical clinical state.
 *
 * Safety:
 * - Navigation actions only
 * - No auto-mutations
 * - Patient-contextual
 * - Role-aware
 */

import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  FlaskConical,
  Pill,
  Stethoscope,
  ClipboardList,
  Clock,
  ChevronRight,
} from 'lucide-react';
import './pending-work-panel.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

type WorkItemUrgency = 'routine' | 'attention' | 'urgent' | 'critical';

interface PendingWorkItem {
  id: string;
  label: string;
  description: string;
  icon: React.ReactNode;
  urgency: WorkItemUrgency;
  route: string;
  count?: number;
}

/* ────────────────────────────────────────────────────────────────────
   DERIVE PENDING WORK
   ──────────────────────────────────────────────────────────────────── */

function derivePendingWork(
  encounters: any[],
  diagnoses: any[],
  prescriptions: any[],
  labOrders: any[],
  patientId: string,
): PendingWorkItem[] {
  const items: PendingWorkItem[] = [];

  // Pending lab orders
  const pendingLabs = (labOrders || []).filter(
    (o: any) => !['reported', 'verified', 'cancelled'].includes(o.status?.toLowerCase()),
  );
  if (pendingLabs.length > 0) {
    const hasCritical = pendingLabs.some(
      (o: any) => o.priority === 'stat' || o.priority === 'critical',
    );
    items.push({
      id: 'pending-labs',
      label: 'Pending Labs',
      description: `${pendingLabs.length} lab order${pendingLabs.length > 1 ? 's' : ''} awaiting results`,
      icon: <FlaskConical size={14} />,
      urgency: hasCritical ? 'critical' : 'attention',
      route: `/clinical/patients/${patientId}?ws=lab`,
      count: pendingLabs.length,
    });
  }

  // Active encounters needing documentation
  const openEncounters = (encounters || []).filter(
    (e: any) => e.status === 'open' || e.status === 'in_progress',
  );
  if (openEncounters.length > 0) {
    items.push({
      id: 'open-encounters',
      label: 'Open Encounters',
      description: `${openEncounters.length} encounter${openEncounters.length > 1 ? 's' : ''} requiring documentation`,
      icon: <Stethoscope size={14} />,
      urgency: 'attention',
      route: `/clinical/patients/${patientId}?ws=encounters`,
      count: openEncounters.length,
    });
  }

  // Active prescriptions
  const activePrescriptions = (prescriptions || []).filter(
    (p: any) => p.status === 'active',
  );
  if (activePrescriptions.length > 0) {
    items.push({
      id: 'active-meds',
      label: 'Active Medications',
      description: `${activePrescriptions.length} prescription${activePrescriptions.length > 1 ? 's' : ''} in effect`,
      icon: <Pill size={14} />,
      urgency: 'routine',
      route: `/clinical/patients/${patientId}?ws=medications`,
      count: activePrescriptions.length,
    });
  }

  // Active diagnoses
  const activeDiagnoses = (diagnoses || []).filter(
    (d: any) => d.status === 'active',
  );
  if (activeDiagnoses.length > 0) {
    items.push({
      id: 'active-diagnoses',
      label: 'Active Diagnoses',
      description: `${activeDiagnoses.length} active problem${activeDiagnoses.length > 1 ? 's' : ''}`,
      icon: <ClipboardList size={14} />,
      urgency: 'routine',
      route: `/clinical/patients/${patientId}?ws=diagnoses`,
      count: activeDiagnoses.length,
    });
  }

  // Sort by urgency
  const urgencyOrder: Record<WorkItemUrgency, number> = {
    critical: 0, urgent: 1, attention: 2, routine: 3,
  };
  items.sort((a, b) => urgencyOrder[a.urgency] - urgencyOrder[b.urgency]);

  return items;
}

/* ────────────────────────────────────────────────────────────────────
   URGENCY STYLING
   ──────────────────────────────────────────────────────────────────── */

const URGENCY_STYLE: Record<WorkItemUrgency, { color: string; bg: string; border: string }> = {
  routine: { color: 'var(--text-secondary)', bg: 'var(--white)', border: 'var(--border-subtle)' },
  attention: { color: 'var(--blue-700)', bg: 'var(--blue-50)', border: 'var(--blue-200)' },
  urgent: { color: 'var(--amber-700)', bg: 'var(--amber-50)', border: 'var(--amber-200)' },
  critical: { color: 'var(--red-700)', bg: 'var(--red-50)', border: 'var(--red-200)' },
};

/* ────────────────────────────────────────────────────────────────────
   MAIN PENDING WORK PANEL
   ──────────────────────────────────────────────────────────────────── */

interface PendingWorkPanelProps {
  encounters: any[];
  diagnoses: any[];
  prescriptions: any[];
  labOrders: any[];
  patientId: string;
}

export function PendingWorkPanel({
  encounters,
  diagnoses,
  prescriptions,
  labOrders,
  patientId,
}: PendingWorkPanelProps) {
  const navigate = useNavigate();

  const pendingWork = useMemo(
    () => derivePendingWork(encounters, diagnoses, prescriptions, labOrders, patientId),
    [encounters, diagnoses, prescriptions, labOrders, patientId],
  );

  if (pendingWork.length === 0) return null;

  const criticalCount = pendingWork.filter((i) => i.urgency === 'critical').length;
  const attentionCount = pendingWork.filter((i) => i.urgency === 'attention' || i.urgency === 'urgent').length;

  return (
    <div className="pwp" role="region" aria-label="Pending clinical work">
      <div className="pwp__header">
        <span className="pwp__title">
          <Clock size={13} />
          Pending Work
        </span>
        <div className="pwp__badges">
          {criticalCount > 0 && (
            <span className="pwp__badge pwp__badge--critical">{criticalCount} critical</span>
          )}
          {attentionCount > 0 && (
            <span className="pwp__badge pwp__badge--attention">{attentionCount} needs attention</span>
          )}
        </div>
      </div>

      <div className="pwp__items" role="list">
        {pendingWork.map((item) => {
          const style = URGENCY_STYLE[item.urgency];
          return (
            <button
              key={item.id}
              type="button"
              className="pwp__item"
              style={{ borderColor: style.border, background: style.bg }}
              onClick={() => navigate(item.route)}
              aria-label={`${item.label}: ${item.description}`}
              role="listitem"
            >
              <span className="pwp__item-icon" style={{ color: style.color }}>
                {item.icon}
              </span>
              <div className="pwp__item-body">
                <span className="pwp__item-label" style={{ color: style.color }}>
                  {item.label}
                  {item.count !== undefined && (
                    <span className="pwp__item-count">{item.count}</span>
                  )}
                </span>
                <span className="pwp__item-desc">{item.description}</span>
              </div>
              <ChevronRight size={12} className="pwp__item-arrow" style={{ color: style.color }} />
            </button>
          );
        })}
      </div>
    </div>
  );
}

export default PendingWorkPanel;
