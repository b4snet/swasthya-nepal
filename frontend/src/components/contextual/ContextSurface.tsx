/**
 * ContextSurface — Contextual Action Launcher (Phase 116)
 *
 * Answers: "WHAT DO I WANT TO DO IN THIS CLINICAL CONTEXT?"
 *
 * Interaction model:
 *   GLOBAL SYSTEM → CLINICAL CONTEXT → USER INTENT → WORKSPACE
 *
 * This replaces the generic DomainCommandSurface with a richer
 * clinical action surface that understands:
 * - urgency (normal/info/attention/warning/critical)
 * - role (who can do what)
 * - status (live counts, waiting, ready)
 * - workflow position (what just happened, what's next)
 *
 * Design constraints:
 * - Light-only. Clinical. Calm. Fast.
 * - NO dark mode. NO glassmorphism. NO gaming UI.
 * - Feels like precision clinical instrumentation.
 * - Every action must be keyboard-accessible.
 * - Focus management follows clinical workflow.
 */

import { useEffect, useRef, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { useTenant } from '../../context/TenantContext';
import type { NavModule } from '../../navigation/modules';
import {
  AlertTriangle,
  ArrowRight,
  X,
} from 'lucide-react';
import './context-surface.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

export type UrgencyLevel = 'normal' | 'info' | 'attention' | 'warning' | 'critical';

export interface ContextAction {
  /** Unique key */
  key: string;
  /** Display label */
  label: string;
  /** Short purpose description */
  description?: string;
  /** Icon component — LucideIcon or JSX */
  icon: React.ReactNode;
  /** Route to navigate to */
  to: string;
  /** Urgency level */
  urgency: UrgencyLevel;
  /** Optional live count */
  count?: number;
  /** Status label — e.g., "3 waiting", "Ready" */
  statusLabel?: string;
  /** Whether this action is disabled */
  disabled?: boolean;
  /** Disabled reason */
  disabledReason?: string;
  /** Whether this is the primary recommended action */
  isRecommended?: boolean;
  /** Category grouping label */
  category?: string;
}

export interface ContextSurfaceProps {
  /** The module whose actions to show */
  module: NavModule;
  /** Whether the surface is open */
  open: boolean;
  /** Callback to close */
  onClose: () => void;
  /** Optional additional actions beyond module children */
  extraActions?: ContextAction[];
}

/* ────────────────────────────────────────────────────────────────────
   URGENCY CONFIGURATION
   ──────────────────────────────────────────────────────────────────── */

const URGENCY_CONFIG: Record<UrgencyLevel, {
  color: string;
  bg: string;
  border: string;
  label: string;
  icon: React.ReactNode;
}> = {
  normal: {
    color: 'var(--text-secondary)',
    bg: 'var(--gray-25)',
    border: 'var(--border-subtle)',
    label: '',
    icon: null,
  },
  info: {
    color: 'var(--blue-700)',
    bg: 'var(--blue-50)',
    border: 'var(--blue-200)',
    label: '',
    icon: null,
  },
  attention: {
    color: 'var(--amber-700)',
    bg: 'var(--amber-50)',
    border: 'var(--amber-200)',
    label: 'Attention',
    icon: <AlertTriangle size={11} />,
  },
  warning: {
    color: 'var(--amber-700)',
    bg: 'var(--amber-50)',
    border: 'var(--amber-200)',
    label: 'Warning',
    icon: <AlertTriangle size={11} />,
  },
  critical: {
    color: 'var(--red-700)',
    bg: 'var(--red-50)',
    border: 'var(--red-200)',
    label: 'Critical',
    icon: <AlertTriangle size={11} />,
  },
};

/* ────────────────────────────────────────────────────────────────────
   URGENCY INDICATOR
   ──────────────────────────────────────────────────────────────────── */

export function UrgencyIndicator({ urgency }: { urgency: UrgencyLevel }) {
  if (urgency === 'normal') return null;
  const config = URGENCY_CONFIG[urgency];
  return (
    <span
      className="urgency-indicator"
      style={{ color: config.color, background: config.bg, borderColor: config.border }}
      aria-label={`${config.label} priority`}
    >
      {config.icon}
      {config.label && <span>{config.label}</span>}
    </span>
  );
}

/* ────────────────────────────────────────────────────────────────────
   ACTION CARD
   ──────────────────────────────────────────────────────────────────── */

function ActionCard({
  action,
  isFirst,
}: {
  action: ContextAction;
  index: number;
  isFirst: boolean;
}) {
  const navigate = useNavigate();
  const urgency = URGENCY_CONFIG[action.urgency];
  const isHigh = action.urgency === 'critical' || action.urgency === 'warning';

  return (
    <button
      type="button"
      className={[
        'ctx-action',
        isHigh ? 'ctx-action--high' : '',
        action.isRecommended ? 'ctx-action--recommended' : '',
        action.disabled ? 'ctx-action--disabled' : '',
      ].filter(Boolean).join(' ')}
      style={{
        borderLeftColor: urgency.color,
      }}
      onClick={() => {
        if (!action.disabled) {
          navigate(action.to);
        }
      }}
      disabled={action.disabled}
      aria-label={`${action.label}${action.description ? ` — ${action.description}` : ''}${action.count != null ? `, ${action.count} items` : ''}`}
      aria-describedby={action.disabled && action.disabledReason ? `ctx-action-disabled-${action.key}` : undefined}
      tabIndex={isFirst ? 0 : -1}
      data-testid={`ctx-action-${action.key}`}
    >
      <div className="ctx-action__icon" style={{ color: urgency.color, background: urgency.bg }}>
        {action.icon}
      </div>

      <div className="ctx-action__content">
        <div className="ctx-action__top-row">
          <span className="ctx-action__label">{action.label}</span>
          {(action.urgency === 'critical' || action.urgency === 'warning') && (
            <UrgencyIndicator urgency={action.urgency} />
          )}
        </div>
        {action.description && (
          <span className="ctx-action__description">{action.description}</span>
        )}
        {action.statusLabel && (
          <span className="ctx-action__status" style={{ color: urgency.color }}>
            {action.statusLabel}
          </span>
        )}
      </div>

      <div className="ctx-action__right">
        {action.count != null && (
          <span className="ctx-action__count" style={{ color: urgency.color, background: urgency.bg }}>
            {action.count}
          </span>
        )}
        <ArrowRight size={14} className="ctx-action__arrow" />
      </div>

      {action.disabled && action.disabledReason && (
        <span id={`ctx-action-disabled-${action.key}`} className="visually-hidden">
          {action.disabledReason}
        </span>
      )}
    </button>
  );
}

/* ────────────────────────────────────────────────────────────────────
   CATEGORY GROUP
   ──────────────────────────────────────────────────────────────────── */

function CategoryGroup({
  label,
  actions,
  startIndex,
}: {
  label: string;
  actions: ContextAction[];
  startIndex: number;
}) {
  return (
    <div className="ctx-category">
      <div className="ctx-category__label">{label}</div>
      <div className="ctx-category__items">
        {actions.map((action, i) => (
          <ActionCard
            key={action.key}
            action={action}
            index={startIndex + i}
            isFirst={startIndex + i === 0}
          />
        ))}
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CONTEXT SURFACE
   ──────────────────────────────────────────────────────────────────── */

export function ContextSurface({
  module,
  open,
  onClose,
  extraActions = [],
}: ContextSurfaceProps) {
  const { t } = useI18n();
  const { hasRole } = useTenant();
  const surfaceRef = useRef<HTMLDivElement>(null);
  const firstActionRef = useRef<HTMLButtonElement>(null);

  // Build actions from module children
  const moduleActions: ContextAction[] = useMemo(() => {
    return module.children.map((child) => ({
      key: child.key,
      label: t(child.labelKey),
      description: child.description,
      icon: <child.Icon size={18} strokeWidth={1.75} />,
      to: child.to,
      urgency: 'normal' as UrgencyLevel,
      category: undefined,
    }));
  }, [module, t]);

  // Combine with extra actions
  const allActions = useMemo(() => {
    return [...extraActions, ...moduleActions];
  }, [extraActions, moduleActions]);

  // Filter by role
  const visibleActions = useMemo(() => {
    return allActions.filter(
      (action) => {
        // Extra actions don't have role filtering (they're pre-filtered)
        const isExtra = extraActions.some((e) => e.key === action.key);
        if (isExtra) return true;
        // Module children have role filtering
        const child = module.children.find((c) => c.key === action.key);
        if (!child) return true;
        return child.roles.length === 0 || child.roles.some((r) => hasRole(r));
      },
    );
  }, [allActions, extraActions, module.children, hasRole]);

  // Group by category
  const categorized = useMemo(() => {
    const groups: { label: string; actions: ContextAction[] }[] = [];
    const uncategorized: ContextAction[] = [];

    for (const action of visibleActions) {
      if (action.category) {
        let group = groups.find((g) => g.label === action.category);
        if (!group) {
          group = { label: action.category, actions: [] };
          groups.push(group);
        }
        group.actions.push(action);
      } else {
        uncategorized.push(action);
      }
    }

    // Uncategorized first, then categorized
    return [...(uncategorized.length > 0 ? [{ label: '', actions: uncategorized }] : []), ...groups];
  }, [visibleActions]);

  // Keyboard navigation
  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      onClose();
      return;
    }

    const allButtons = surfaceRef.current?.querySelectorAll<HTMLElement>(
      '.ctx-action:not([disabled])',
    );
    if (!allButtons?.length) return;

    const currentIndex = Array.from(allButtons).indexOf(
      document.activeElement as HTMLElement,
    );

    if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
      e.preventDefault();
      const next = currentIndex < allButtons.length - 1 ? currentIndex + 1 : 0;
      allButtons[next].focus();
    }

    if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
      e.preventDefault();
      const prev = currentIndex > 0 ? currentIndex - 1 : allButtons.length - 1;
      allButtons[prev].focus();
    }

    // Home/End
    if (e.key === 'Home') {
      e.preventDefault();
      allButtons[0].focus();
    }
    if (e.key === 'End') {
      e.preventDefault();
      allButtons[allButtons.length - 1].focus();
    }
  }, [onClose]);

  // Focus management
  useEffect(() => {
    if (open) {
      requestAnimationFrame(() => firstActionRef.current?.focus());
    }
  }, [open]);

  // Click outside
  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (surfaceRef.current && !surfaceRef.current.contains(e.target as Node)) {
        onClose();
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open, onClose]);

  if (!open || visibleActions.length === 0) return null;

  const moduleLabel = t(module.labelKey);

  return (
    <div
      className="ctx-surface"
      ref={surfaceRef}
      role="navigation"
      aria-label={`${moduleLabel} actions`}
      onKeyDown={handleKeyDown}
    >
      {/* Header */}
      <div className="ctx-surface__header">
        <div className="ctx-surface__header-left">
          <div className="ctx-surface__domain-icon">
            <module.Icon size={18} strokeWidth={1.75} />
          </div>
          <div className="ctx-surface__header-text">
            <h2 className="ctx-surface__title">{moduleLabel}</h2>
            <span className="ctx-surface__subtitle">
              {visibleActions.length} action{visibleActions.length !== 1 ? 's' : ''} available
            </span>
          </div>
        </div>
        <button
          type="button"
          className="ctx-surface__close"
          onClick={onClose}
          aria-label={`Close ${moduleLabel}`}
        >
          <X size={16} />
        </button>
      </div>

      {/* Actions */}
      <div className="ctx-surface__body">
        {categorized.map((group, groupIdx) => {
          const startIndex = categorized
            .slice(0, groupIdx)
            .reduce((sum, g) => sum + g.actions.length, 0);
          return (
            <CategoryGroup
              key={group.label || '_default'}
              label={group.label}
              actions={group.actions}
              startIndex={startIndex}
            />
          );
        })}
      </div>

      {/* Footer hint */}
      <div className="ctx-surface__footer">
        <span className="ctx-surface__footer-hint">
          ↑↓ Navigate · Enter select · Esc close
        </span>
      </div>
    </div>
  );
}

export default ContextSurface;
