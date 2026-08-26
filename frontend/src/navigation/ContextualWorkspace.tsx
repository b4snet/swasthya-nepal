import { useNavigate } from 'react-router-dom';
import { useI18n } from '../i18n/I18nProvider';
import type { NavModule } from './modules';
import './contextual-workspace.css';

/**
 * WorkspaceCard — a single workspace launcher.
 *
 * Icon + name + optional description.
 * Visually distinct, touch-friendly, keyboard-accessible.
 */
function WorkspaceCard({
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
      className={`workspace-card ${isActive ? 'workspace-card--active' : ''}`}
      onClick={() => navigate(item.to)}
      aria-label={t(item.labelKey)}
      aria-current={isActive ? 'page' : undefined}
      data-testid={`workspace-${moduleKey}-${item.key}`}
    >
      <div className="workspace-card__icon">
        <item.Icon size={22} strokeWidth={1.75} />
      </div>
      <div className="workspace-card__body">
        <span className="workspace-card__name">{t(item.labelKey)}</span>
        {item.description && (
          <span className="workspace-card__desc">{item.description}</span>
        )}
      </div>
    </button>
  );
}

/**
 * ContextualWorkspace — the secondary surface that appears
 * when a primary domain is selected.
 *
 * Shows workspace launcher cards for the active domain.
 * This replaces the old expanding child-list navigation.
 */
export function ContextualWorkspace({
  activeModule,
  pathname,
}: {
  activeModule: NavModule | undefined;
  pathname: string;
}) {
  const { t } = useI18n();

  // Dashboard is the global home — no contextual launcher
  if (!activeModule || activeModule.persistent || activeModule.children.length === 0) {
    return null;
  }

  const isActiveChild = (child: NavModule['children'][number]) =>
    pathname === child.to ||
    (child.to !== activeModule.defaultTo && pathname.startsWith(child.to + '/'));

  return (
    <div className="contextual-workspace" role="region" aria-label={`${t(activeModule.labelKey)} workspace`}>
      <div className="contextual-workspace__header">
        <span className="contextual-workspace__domain-icon">
          <activeModule.Icon size={18} strokeWidth={1.75} />
        </span>
        <h2 className="contextual-workspace__title">{t(activeModule.labelKey)}</h2>
      </div>

      <div className="contextual-workspace__grid" role="list">
        {activeModule.children.map((child) => (
          <WorkspaceCard
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
