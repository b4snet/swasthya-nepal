import { useEffect, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import type { LucideIcon } from 'lucide-react';
import { useI18n } from '../i18n/I18nProvider';
import { useTenant } from '../context/TenantContext';
import type { NavModule } from '../navigation/modules';
import './domain-command-surface.css';

/* ------------------------------------------------------------------
   DOMAIN COMMAND SURFACE
   
   When a user clicks a domain in the sidebar, this surface reveals
   contextual command tiles — the real operational entry points.
   
   Design: DOMAIN → CONTEXT → AVAILABLE WORK → ACTION → RESULT
   Not: MODULE → SUBMODULE → MENU → PAGE → SEARCH → ACTION
   ------------------------------------------------------------------ */

export interface DomainCommand {
  /** Unique key */
  key: string;
  /** Display label */
  label: string;
  /** Short purpose description */
  purpose?: string;
  /** Icon component */
  Icon: LucideIcon;
  /** Route to navigate to */
  to: string;
  /** Roles that can see this command */
  roles: string[];
  /** Optional live count (e.g., "12 waiting") */
  count?: number;
  /** Semantic tone for the count/status */
  tone?: 'normal' | 'warning' | 'critical' | 'info';
  /** Whether this is a high-priority/exception command */
  priority?: 'primary' | 'secondary' | 'exception';
}

interface DomainCommandSurfaceProps {
  /** The module whose commands to show */
  module: NavModule;
  /** Whether the surface is open */
  open: boolean;
  /** Callback to close the surface */
  onClose: () => void;
}

/**
 * Build contextual commands from a module's children.
 * This maps the existing navigation structure into command tiles.
 */
function buildCommands(module: NavModule): DomainCommand[] {
  return module.children.map((child) => ({
    key: child.key,
    label: child.labelKey.replace('nav.', '').replace(/([A-Z])/g, ' $1').trim(),
    purpose: child.description,
    Icon: child.Icon,
    to: child.to,
    roles: child.roles,
    priority: 'secondary' as const,
  }));
}

export function DomainCommandSurface({ module, open, onClose }: DomainCommandSurfaceProps) {
  const navigate = useNavigate();
  const { t } = useI18n();
  const { hasRole } = useTenant();
  const surfaceRef = useRef<HTMLDivElement>(null);
  const firstTileRef = useRef<HTMLButtonElement>(null);

  const commands = buildCommands(module);

  // Filter by role
  const visibleCommands = commands.filter(
    (cmd) => cmd.roles.length === 0 || cmd.roles.some((r) => hasRole(r)),
  );

  // Handle keyboard navigation
  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      onClose();
      return;
    }

    // Arrow key navigation within the grid
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
      e.preventDefault();
      const tiles = surfaceRef.current?.querySelectorAll<HTMLElement>('[role="button"]');
      if (!tiles?.length) return;
      const current = document.activeElement as HTMLElement;
      const idx = Array.from(tiles).indexOf(current);
      const next = idx < tiles.length - 1 ? idx + 1 : 0;
      tiles[next].focus();
    }

    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
      e.preventDefault();
      const tiles = surfaceRef.current?.querySelectorAll<HTMLElement>('[role="button"]');
      if (!tiles?.length) return;
      const current = document.activeElement as HTMLElement;
      const idx = Array.from(tiles).indexOf(current);
      const prev = idx > 0 ? idx - 1 : tiles.length - 1;
      tiles[prev].focus();
    }
  }, [onClose]);

  // Focus first tile on open
  useEffect(() => {
    if (open) {
      // Small delay to allow DOM to render
      requestAnimationFrame(() => firstTileRef.current?.focus());
    }
  }, [open]);

  // Click outside to close
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

  if (!open || visibleCommands.length === 0) return null;

  const moduleLabel = t(module.labelKey);

  return (
    <div
      className="domain-surface"
      ref={surfaceRef}
      role="navigation"
      aria-label={`${moduleLabel} commands`}
      onKeyDown={handleKeyDown}
    >
      {/* Domain header */}
      <div className="domain-surface__header">
        <module.Icon size={20} strokeWidth={1.75} className="domain-surface__icon" />
        <h2 className="domain-surface__title">{moduleLabel}</h2>
        <button
          type="button"
          className="domain-surface__close"
          onClick={onClose}
          aria-label={`Close ${moduleLabel} commands`}
        >
          ×
        </button>
      </div>

      {/* Command grid */}
      <div className="domain-surface__grid" role="group" aria-label={`${moduleLabel} actions`}>
        {visibleCommands.map((cmd, i) => (
          <button
            key={cmd.key}
            ref={i === 0 ? firstTileRef : undefined}
            type="button"
            className={`domain-tile domain-tile--${cmd.priority ?? 'secondary'} ${cmd.tone ? `domain-tile--${cmd.tone}` : ''}`}
            role="button"
            onClick={() => {
              navigate(cmd.to);
              onClose();
            }}
            aria-label={`${cmd.label}${cmd.purpose ? ` — ${cmd.purpose}` : ''}${cmd.count != null ? `, ${cmd.count} items` : ''}`}
          >
            <div className="domain-tile__icon-wrap">
              <cmd.Icon size={20} strokeWidth={1.75} />
            </div>
            <div className="domain-tile__content">
              <span className="domain-tile__label">{cmd.label}</span>
              {cmd.purpose && (
                <span className="domain-tile__purpose">{cmd.purpose}</span>
              )}
            </div>
            {cmd.count != null && (
              <span className={`domain-tile__count ${cmd.tone ? `domain-tile__count--${cmd.tone}` : ''}`}>
                {cmd.count}
              </span>
            )}
          </button>
        ))}
      </div>
    </div>
  );
}
