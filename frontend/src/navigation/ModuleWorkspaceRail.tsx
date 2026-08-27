/**
 * ModuleWorkspaceRail — Clinical Workspace Navigation (Phase 127)
 *
 * A compact vertical action surface that replaces the horizontal ContextualWorkspace
 * chips when inside a clinical module. Makes the dashboard → module transition feel
 * like entering a dedicated clinical workspace rather than expanding a submenu.
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

import { useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useI18n } from '../i18n/I18nProvider';
import type { NavModule } from './modules';
import './module-workspace-rail.css';

/**
 * ModuleActionCard — a single workspace action as a compact vertical card.
 *
 * Icon + label + optional count/status.
 * Active state shows selection.
 * Touch-friendly (44px+ hit target).
 * Keyboard navigable.
 */
function ModuleActionCard({
  item,
  moduleKey,
  isActive,
}: {
  item: NavModule['children'][number];
  moduleKey: string;
  isActive: boolean;
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
        <item.Icon size={16} strokeWidth={1.75} />
      </span>
      <span className="module-action__label">{t(item.labelKey)}</span>
    </button>
  );
}

/**
 * ModuleWorkspaceRail — compact vertical workspace navigation for the active clinical domain.
 *
 * Appears between the global header/workspace rail and page content.
 * Shows available workspace launchers for the selected clinical module as
 * vertically stacked compact action cards.
 *
 * Dashboard and standalone modules (persistent) do not show the rail.
 *
 * Conceptual model:
 * ```
 * ┌──────────────────────────────────────────────────────────┐
 * │ Module: EMERGENCY                                        │
 * │                                                          │
 * │ ┌────────────────┐                                       │
 * │ │ ◉ Triage       │                                       │
 * │ ├────────────────┤                                       │
 * │ │ ◉ Active Cases │                                       │
 * │ ├────────────────┤                                       │
 * │ │ ◉ Critical     │                                       │
 * │ ├────────────────┤                                       │
 * │ │ ◉ Waiting      │                                       │
 * │ ├────────────────┤                                       │
 * │ │ ◉ Disposition  │                                       │
 * │ └────────────────┘                                       │
 * └──────────────────────────────────────────────────────────┘
 * ```
 */
export function ModuleWorkspaceRail({
  activeModule,
  pathname,
}: {
  activeModule: NavModule | undefined;
  pathname: string;
}) {
  const { t } = useI18n();
  const railRef = useRef<HTMLDivElement>(null);

  // Dashboard and standalone modules — no workspace rail
  if (!activeModule || activeModule.persistent || activeModule.children.length === 0) {
    return null;
  }

  const isActiveChild = (child: NavModule['children'][number]) =>
    pathname === child.to ||
    (child.to !== activeModule.defaultTo && pathname.startsWith(child.to + '/'));

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

  return (
    <div className="workspace-rail" role="navigation" aria-label={`${t(activeModule.labelKey)} workspace`}>
      <div className="workspace-rail__header">
        <span className="workspace-rail__domain-icon">
          <activeModule.Icon size={14} strokeWidth={1.75} />
        </span>
        <h2 className="workspace-rail__title">{t(activeModule.labelKey)}</h2>
      </div>

      <div className="workspace-rail__actions" ref={railRef} role="list">
        {activeModule.children.map((child) => (
          <ModuleActionCard
            key={child.key}
            item={child}
            moduleKey={activeModule.key}
            isActive={isActiveChild(child)}
          />
        ))}
      </div>
    </div>
  );
}
