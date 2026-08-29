/**
 * ModuleWorkspaceRail — Clinical Workspace Navigation (Phase 127/129)
 *
 * A compact vertical action surface that replaces the horizontal ContextualWorkspace
 * chips when inside a clinical module. Makes the dashboard → module transition feel
 * like entering a dedicated clinical workspace rather than expanding a submenu.
 *
 * Phase 129 enhancements:
 * - Urgency badges (count indicators) on actions with pending items
 * - Patient context awareness when inside a patient route
 * - Grouped actions by category (Clinical, Operations, etc.)
 * - Compact clinical instrument panel aesthetic
 *
 * Visual language:
 *   - compact vertical cards with icon + label + optional count/urgency
 *   - no nested menus
 *   - no expanding accordions
 *   - calm, clinical, highly scannable
 *   - accessible, keyboard-navigable
 *   - touch-friendly (44px+ hit target)
 *
 * Safety:
 *   - Only shows authorized workspaces
 *   - Uses existing route structure
 *   - No duplicate navigation state
 *   - Dashboard is unaffected
 */

import { useRef, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useI18n } from '../i18n/I18nProvider';
import type { NavModule } from './modules';
import { Lock, UserRound } from 'lucide-react';
import './module-workspace-rail.css';

/* ────────────────────────────────────────────────────────────────────
   ACTION BADGE — compact count/status indicator
   ──────────────────────────────────────────────────────────────────── */

function ActionBadge({
  count,
  urgency,
}: {
  count?: number;
  urgency?: 'routine' | 'attention' | 'urgent' | 'critical';
}) {
  if (count == null || count === 0) return null;

  return (
    <span
      className={`module-action__badge ${urgency ? `module-action__badge--${urgency}` : ''}`}
      aria-label={`${count} item${count !== 1 ? 's' : ''}`}
    >
      {count > 99 ? '99+' : count}
    </span>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MODULE ACTION CARD — compact vertical card
   ──────────────────────────────────────────────────────────────────── */

function ModuleActionCard({
  item,
  moduleKey,
  isActive,
  badge,
  badgeUrgency,
}: {
  item: NavModule['children'][number];
  moduleKey: string;
  isActive: boolean;
  badge?: number;
  badgeUrgency?: 'routine' | 'attention' | 'urgent' | 'critical';
}) {
  const navigate = useNavigate();
  const { t } = useI18n();

  return (
    <button
      type="button"
      className={`module-action ${isActive ? 'module-action--active' : ''}`}
      onClick={() => navigate(item.to)}
      aria-label={t(item.labelKey)}
      aria-current={isActive ? 'page' : undefined}
      data-testid={`module-action-${moduleKey}-${item.key}`}
      title={item.description || t(item.labelKey)}
    >
      <span className="module-action__icon">
        <item.Icon size={15} strokeWidth={1.75} />
      </span>
      <span className="module-action__label">{t(item.labelKey)}</span>
      <ActionBadge count={badge} urgency={badgeUrgency} />
    </button>
  );
}

/* ────────────────────────────────────────────────────────────────────
   RAIL SECTION — grouped actions
   ──────────────────────────────────────────────────────────────────── */

function RailSection({
  label,
  children,
}: {
  label?: string;
  children: React.ReactNode;
}) {
  if (!label) return <>{children}</>;

  return (
    <div className="rail-section">
      <span className="rail-section__label">{label}</span>
      <div className="rail-section__items">
        {children}
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   PATIENT CONTEXT INDICATOR
   ──────────────────────────────────────────────────────────────────── */

function PatientContextIndicator({ patientId }: { patientId: string }) {
  return (
    <div className="workspace-rail__patient" role="status" aria-label="Active patient context">
      <span className="workspace-rail__patient-icon">
        <UserRound size={12} />
      </span>
      <span className="workspace-rail__patient-id mono">{patientId.slice(0, 8)}</span>
      <span className="workspace-rail__patient-lock" title="Patient context locked">
        <Lock size={10} />
      </span>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   ACTION GROUPING CONFIGURATION
   ──────────────────────────────────────────────────────────────────── */

/** Maps module keys to action groupings. Only modules with explicit groupings use sections. */
const ACTION_GROUPS: Record<string, { group: string; keys: string[] }[]> = {
  emergency: [
    { group: 'Acute', keys: ['er-triage', 'er-cases'] },
    { group: 'Operations', keys: ['er-queue'] },
  ],
  inpatient: [
    { group: 'Clinical', keys: ['ipd-nursing', 'ipd-discharge'] },
    { group: 'Operations', keys: ['ipd-admissions', 'ipd-wards', 'ipd-beds', 'ipd-ops'] },
  ],
  clinical: [
    { group: 'Patients', keys: ['clin-patients', 'clin-flow'] },
    { group: 'Clinical', keys: ['clin-appointments', 'clin-queue', 'clin-encounters', 'clin-forms', 'clin-referrals'] },
  ],
  laboratory: [
    { group: 'Processing', keys: ['lab-orders', 'lab-specimens', 'lab-worklists'] },
    { group: 'Results', keys: ['lab-results', 'lab-reports', 'lab-critical-values'] },
  ],
  pharmacy: [
    { group: 'Clinical', keys: ['pharm-prescriptions', 'pharm-dispensing'] },
    { group: 'Inventory', keys: ['pharm-inventory', 'pharm-returns'] },
  ],
  finance: [
    { group: 'Billing', keys: ['fin-billing', 'fin-revenue'] },
    { group: 'Management', keys: ['fin-budgets', 'fin-expenses', 'fin-accounting', 'fin-periods', 'fin-settings'] },
  ],
};

/**
 * Group module children by configured categories.
 * Returns grouped actions with labels.
 */
function groupActions(
  children: NavModule['children'][number][],
  moduleKey: string,
): { label: string; actions: NavModule['children'][number][] }[] {
  const groups = ACTION_GROUPS[moduleKey];

  if (!groups) {
    // No explicit grouping — all actions in a single group
    return [{ label: '', actions: children }];
  }

  const result: { label: string; actions: NavModule['children'][number][] }[] = [];
  const assigned = new Set<string>();

  for (const group of groups) {
    const actions = children.filter((c) => group.keys.includes(c.key));
    if (actions.length > 0) {
      result.push({ label: group.group, actions });
      actions.forEach((a) => assigned.add(a.key));
    }
  }

  // Uncategorized actions go in an unlabeled group
  const uncategorized = children.filter((c) => !assigned.has(c.key));
  if (uncategorized.length > 0) {
    result.push({ label: '', actions: uncategorized });
  }

  return result;
}

/* ────────────────────────────────────────────────────────────────────
   MAIN MODULE WORKSPACE RAIL
   ──────────────────────────────────────────────────────────────────── */

export function ModuleWorkspaceRail({
  activeModule,
  pathname,
  patientId,
  badges,
}: {
  activeModule: NavModule | undefined;
  pathname: string;
  /** When inside a patient route, show patient context indicator */
  patientId?: string | null;
  /** Optional badge counts for specific actions — keyed by child.key */
  badges?: Record<string, { count: number; urgency?: 'routine' | 'attention' | 'urgent' | 'critical' }>;
}) {
  const { t } = useI18n();
  const railRef = useRef<HTMLDivElement>(null);

  // Group actions by category
  const groups = useMemo(
    () => activeModule && !activeModule.persistent && activeModule.children.length > 0
      ? groupActions(activeModule.children, activeModule.key)
      : [],
    [activeModule],
  );

  // Keyboard navigation within the rail
  useEffect(() => {
    const el = railRef.current;
    if (!el) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        el.scrollBy({ top: 48, behavior: 'smooth' });
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        el.scrollBy({ top: -48, behavior: 'smooth' });
      }
    };

    el.addEventListener('keydown', handleKeyDown);
    return () => el.removeEventListener('keydown', handleKeyDown);
  }, []);

  // Dashboard and standalone modules — no workspace rail
  if (!activeModule || activeModule.persistent || activeModule.children.length === 0) {
    return null;
  }

  const isActiveChild = (child: NavModule['children'][number]) =>
    pathname === child.to ||
    (child.to !== activeModule.defaultTo && pathname.startsWith(child.to + '/'));

  return (
    <div className="workspace-rail" role="navigation" aria-label={`${t(activeModule.labelKey)} workspace`}>
      {/* Module header */}
      <div className="workspace-rail__header">
        <span className="workspace-rail__domain-icon">
          <activeModule.Icon size={14} strokeWidth={1.75} />
        </span>
        <h2 className="workspace-rail__title">{t(activeModule.labelKey)}</h2>
      </div>

      {/* Patient context indicator — only when inside a patient route */}
      {patientId && <PatientContextIndicator patientId={patientId} />}

      {/* Actions — grouped by category */}
      <div className="workspace-rail__actions" ref={railRef} role="list">
        {groups.map((group) => (
          <RailSection key={group.label || '_all'} label={group.label}>
            {group.actions.map((child) => {
              const badgeInfo = badges?.[child.key];
              return (
                <ModuleActionCard
                  key={child.key}
                  item={child}
                  moduleKey={activeModule.key}
                  isActive={isActiveChild(child)}
                  badge={badgeInfo?.count}
                  badgeUrgency={badgeInfo?.urgency}
                />
              );
            })}
          </RailSection>
        ))}
      </div>
    </div>
  );
}

export default ModuleWorkspaceRail;
