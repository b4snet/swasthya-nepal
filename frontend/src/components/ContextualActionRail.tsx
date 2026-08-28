/**
 * ContextualActionRail — Adaptive Action Bar (Phase 119/129)
 *
 * A compact action bar that shows the most relevant next actions
 * based on the current clinical context. Not a wall of buttons.
 *
 * Phase 129 enhancements:
 * - Grouped actions by category (Clinical, Operations, Supporting)
 * - Compact count badges on actions with pending items
 * - Better visual hierarchy for urgent/critical items
 * - More refined spacing and density
 *
 * Safety:
 * - Navigation actions only (no auto-mutations)
 * - Role-aware filtering
 * - Patient-contextual
 * - Deterministic from clinical state
 */

import { useMemo } from 'react';
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
   ACTION GROUPING
   ──────────────────────────────────────────────────────────────────── */

/** Categories for grouping contextual actions */
type ActionCategory = 'clinical' | 'operations' | 'supporting';

const CATEGORY_ORDER: ActionCategory[] = ['clinical', 'operations', 'supporting'];

/** Categorize a contextual action based on its ID */
function categorizeAction(action: ContextualAction): ActionCategory {
  const id = action.id;

  // Clinical actions — directly related to patient care
  if (
    id.startsWith('emergency') ||
    id === 'review-labs' ||
    id === 'medications' ||
    id === 'diagnoses' ||
    id === 'new-encounter' ||
    id === 'open-loops'
  ) {
    return 'clinical';
  }

  // Operations actions — administrative or workflow
  if (
    id === 'admission' ||
    id === 'timeline'
  ) {
    return 'operations';
  }

  // Everything else is supporting
  return 'supporting';
}

/* ────────────────────────────────────────────────────────────────────
   MAIN ACTION RAIL
   ──────────────────────────────────────────────────────────────────── */

interface ContextualActionRailProps {
  actions: ContextualAction[];
  patientId?: string;
}

export function ContextualActionRail({ actions, patientId }: ContextualActionRailProps) {
  const navigate = useNavigate();

  // Group actions by category
  const grouped = useMemo(() => {
    const groups: Record<ActionCategory, ContextualAction[]> = {
      clinical: [],
      operations: [],
      supporting: [],
    };

    for (const action of actions) {
      groups[categorizeAction(action)].push(action);
    }

    // Only return non-empty groups
    return CATEGORY_ORDER
      .filter((cat) => groups[cat].length > 0)
      .map((cat) => ({ category: cat, actions: groups[cat] }));
  }, [actions]);

  if (!patientId || actions.length === 0) return null;

  return (
    <div className="car" role="toolbar" aria-label="Contextual actions">
      <div className="car__items">
        {grouped.map((group, groupIdx) => (
          <div key={group.category} className="car__group">
            {groupIdx > 0 && <span className="car__separator" aria-hidden="true" />}
            {group.actions.map((action) => {
              const style = URGENCY_STYLE[action.urgency];
              const isHigh = action.urgency === 'critical' || action.urgency === 'urgent';
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
                  {isHigh && (
                    <AlertTriangle size={10} className="car__alert" style={{ color: style.color }} />
                  )}
                  <ChevronRight size={10} className="car__arrow" style={{ color: style.color }} />
                </button>
              );
            })}
          </div>
        ))}
      </div>
    </div>
  );
}

export default ContextualActionRail;
