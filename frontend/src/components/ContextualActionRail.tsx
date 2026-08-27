/**
 * ContextualActionRail — Adaptive Action Bar (Phase 119)
 *
 * A compact action bar that shows the most relevant next actions
 * based on the current clinical context. Not a wall of buttons.
 *
 * Priority actions surface first. Less relevant actions stay hidden.
 * The rail adapts without layout chaos.
 *
 * Safety:
 * - Navigation actions only (no auto-mutations)
 * - Role-aware filtering
 * - Patient-contextual
 * - Deterministic from clinical state
 */

import { useNavigate } from 'react-router-dom';
import type { ContextualAction, ClinicalUrgency } from './ClinicalContextEngine';
import {
  AlertTriangle,
  ChevronRight,
} from 'lucide-react';
import './contextual-action-rail.css';

/* ────────────────────────────────────────────────────────────────────
   URGENCY STYLING
   ──────────────────────────────────────────────────────────────────── */

const URGENCY_STYLE: Record<ClinicalUrgency, { color: string; bg: string; border: string }> = {
  routine: { color: 'var(--text-secondary)', bg: 'var(--gray-25)', border: 'var(--border-subtle)' },
  attention: { color: 'var(--blue-700)', bg: 'var(--blue-50)', border: 'var(--blue-200)' },
  urgent: { color: 'var(--amber-700)', bg: 'var(--amber-50)', border: 'var(--amber-200)' },
  critical: { color: 'var(--red-700)', bg: 'var(--red-50)', border: 'var(--red-200)' },
};

/* ────────────────────────────────────────────────────────────────────
   MAIN ACTION RAIL
   ──────────────────────────────────────────────────────────────────── */

interface ContextualActionRailProps {
  actions: ContextualAction[];
  patientId?: string;
}

export function ContextualActionRail({ actions, patientId }: ContextualActionRailProps) {
  const navigate = useNavigate();

  if (!patientId || actions.length === 0) return null;

  return (
    <div className="car" role="toolbar" aria-label="Contextual actions">
      <div className="car__items">
        {actions.map((action) => {
          const style = URGENCY_STYLE[action.urgency];
          return (
            <button
              key={action.id}
              type="button"
              className={`car__item ${action.urgency !== 'routine' ? `car__item--${action.urgency}` : ''}`}
              style={{ borderColor: style.border, background: style.bg }}
              onClick={() => navigate(action.route)}
              title={action.reason}
              aria-label={`${action.label} — ${action.reason}`}
              data-testid={`car-action-${action.id}`}
            >
              <span className="car__icon" style={{ color: style.color }}>{action.icon}</span>
              <span className="car__label" style={{ color: style.color }}>{action.label}</span>
              {action.urgency === 'critical' && (
                <AlertTriangle size={10} className="car__alert" style={{ color: style.color }} />
              )}
              <ChevronRight size={10} className="car__arrow" style={{ color: style.color }} />
            </button>
          );
        })}
      </div>
    </div>
  );
}

export default ContextualActionRail;
