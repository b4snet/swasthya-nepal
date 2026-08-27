/**
 * ContextualWorkspace — Clinical Action Rail (Phase 123)
 *
 * When a primary domain is selected, reveals a compact action surface
 * showing available workspace launchers as icon+label chips.
 *
 * Visual language:
 *   - circular icon containers
 *   - compact, horizontally scrollable
 *   - calm, clinical
 *   - highly scannable
 *   - no giant cards
 *   - no nested menus
 *   - accessible, keyboard-navigable
 *
 * Safety:
 *   - Only shows authorized workspaces
 *   - Uses existing route structure
 *   - No duplicate navigation state
 */

import { useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useI18n } from '../i18n/I18nProvider';
import type { NavModule } from './modules';
import './contextual-workspace.css';

/**
 * ActionChip — a single workspace launcher as a compact chip.
 *
 * Circular icon container + short label.
 * Active state shows selection.
 * Touch-friendly (44px+ hit target).
 */
function ActionChip({
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
      className={`action-chip ${isActive ? 'action-chip--active' : ''}`}
      onClick={() => navigate(item.to)}
      aria-label={t(item.labelKey)}
      aria-current={isActive ? 'page' : undefined}
      data-testid={`action-chip-${moduleKey}-${item.key}`}
      title={item.description || t(item.labelKey)}
    >
      <span className="action-chip__icon">
        <item.Icon size={18} strokeWidth={1.75} />
      </span>
      <span className="action-chip__label">{t(item.labelKey)}</span>
    </button>
  );
}

/**
 * ContextualWorkspace — compact action rail for the active domain.
 *
 * Appears between the global header and page content.
 * Shows available workspace launchers for the selected clinical domain.
 * Horizontally scrollable on smaller viewports.
 * Hidden on mobile (replaced by bottom nav).
 */
export function ContextualWorkspace({
  activeModule,
  pathname,
}: {
  activeModule: NavModule | undefined;
  pathname: string;
}) {
  const { t } = useI18n();
  const scrollRef = useRef<HTMLDivElement>(null);

  // Dashboard and standalone modules — no contextual launcher
  if (!activeModule || activeModule.persistent || activeModule.children.length === 0) {
    return null;
  }

  const isActiveChild = (child: NavModule['children'][number]) =>
    pathname === child.to ||
    (child.to !== activeModule.defaultTo && pathname.startsWith(child.to + '/'));

  // Keyboard navigation within the chip rail
  useEffect(() => {
    const el = scrollRef.current;
    if (!el) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'ArrowRight') {
        e.preventDefault();
        el.scrollBy({ left: 120, behavior: 'smooth' });
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        el.scrollBy({ left: -120, behavior: 'smooth' });
      }
    };

    el.addEventListener('keydown', handleKeyDown);
    return () => el.removeEventListener('keydown', handleKeyDown);
  }, []);

  return (
    <div className="action-rail" role="region" aria-label={`${t(activeModule.labelKey)} actions`}>
      <div className="action-rail__header">
        <span className="action-rail__domain-icon">
          <activeModule.Icon size={15} strokeWidth={1.75} />
        </span>
        <h2 className="action-rail__title">{t(activeModule.labelKey)}</h2>
        <span className="action-rail__count">{activeModule.children.length}</span>
      </div>

      <div className="action-rail__chips" ref={scrollRef} role="list">
        {activeModule.children.map((child) => (
          <ActionChip
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
