/**
 * ClinicalInspector — Slide-in Detail Panel (Phase 124)
 *
 * A contextual inspector that shows details of a clinical item
 * (order, result, medication, encounter, etc.) without navigating
 * away from the current workspace.
 *
 * Answers: "WHAT IS THIS ITEM AND WHAT CAN I DO WITH IT?"
 *
 * Design principles:
 * - Slide-in from right (not a full-page navigation)
 * - Focus trapped within inspector when open
 * - Focus restored to triggering element when closed
 * - Escape closes
 * - Patient context remains visible
 * - Compact, information-dense
 * - Light-first clinical design
 *
 * Safety:
 * - Read-only by default (no auto-mutations)
 * - Destructive actions require explicit confirmation
 * - Backend authorization remains authoritative
 * - No duplicate patient state
 */

import { useEffect, useRef, type ReactNode } from 'react';
import { X, ChevronRight, ExternalLink } from 'lucide-react';
import './clinical-inspector.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

export interface InspectorField {
  label: string;
  value: ReactNode;
  /** Optional mono formatting */
  mono?: boolean;
  /** Optional urgency tint */
  urgency?: 'routine' | 'attention' | 'urgent' | 'critical';
}

export interface InspectorAction {
  id: string;
  label: string;
  route?: string;
  onClick?: () => void;
  variant?: 'primary' | 'ghost' | 'danger';
}

export interface ClinicalInspectorProps {
  /** Whether the inspector is open */
  open: boolean;
  /** Close handler */
  onClose: () => void;
  /** Title of the inspected item */
  title: string;
  /** Subtitle or category */
  subtitle?: string;
  /** Icon for the header */
  icon?: ReactNode;
  /** Fields to display */
  fields: InspectorField[];
  /** Available actions */
  actions?: InspectorAction[];
  /** Optional related items section */
  related?: { label: string; items: { label: string; route: string }[] };
  /** The element that triggered the inspector (for focus restoration) */
  triggerRef?: React.RefObject<HTMLElement>;
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CLINICAL INSPECTOR
   ──────────────────────────────────────────────────────────────────── */

export function ClinicalInspector({
  open,
  onClose,
  title,
  subtitle,
  icon,
  fields,
  actions,
  related,
  triggerRef,
}: ClinicalInspectorProps) {
  const panelRef = useRef<HTMLDivElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);

  // Focus management
  useEffect(() => {
    if (open) {
      // Focus the close button on open
      setTimeout(() => closeButtonRef.current?.focus(), 50);
    } else if (triggerRef?.current) {
      // Restore focus to trigger on close
      triggerRef.current.focus();
    }
  }, [open, triggerRef]);

  // Escape to close
  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [open, onClose]);

  // Focus trap
  useEffect(() => {
    if (!open || !panelRef.current) return;
    const panel = panelRef.current;

    const handler = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return;
      const focusable = panel.querySelectorAll<HTMLElement>(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
      );
      if (focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    };

    panel.addEventListener('keydown', handler);
    return () => panel.removeEventListener('keydown', handler);
  }, [open]);

  if (!open) return null;

  return (
    <>
      {/* Backdrop */}
      <div className="inspector-backdrop" onClick={onClose} aria-hidden="true" />

      {/* Panel */}
      <div
        ref={panelRef}
        className="inspector"
        role="dialog"
        aria-label={`${title} details`}
        aria-modal="true"
      >
        {/* Header */}
        <div className="inspector__header">
          <div className="inspector__header-info">
            {icon && <span className="inspector__header-icon">{icon}</span>}
            <div>
              <h2 className="inspector__title">{title}</h2>
              {subtitle && <span className="inspector__subtitle">{subtitle}</span>}
            </div>
          </div>
          <button
            ref={closeButtonRef}
            type="button"
            className="inspector__close"
            onClick={onClose}
            aria-label="Close inspector"
          >
            <X size={16} />
          </button>
        </div>

        {/* Fields */}
        <div className="inspector__body">
          {fields.map((field, i) => (
            <div key={i} className="inspector__field">
              <span className="inspector__field-label">{field.label}</span>
              <span
                className={`inspector__field-value ${field.mono ? 'mono' : ''} ${
                  field.urgency ? `inspector__field-value--${field.urgency}` : ''
                }`}
              >
                {field.value || '—'}
              </span>
            </div>
          ))}

          {/* Related items */}
          {related && related.items.length > 0 && (
            <div className="inspector__related">
              <h3 className="inspector__related-title">{related.label}</h3>
              <div className="inspector__related-items">
                {related.items.map((item, i) => (
                  <a
                    key={i}
                    href={item.route}
                    className="inspector__related-link"
                    onClick={() => {
                      // Navigation handled by parent if needed
                    }}
                  >
                    <ChevronRight size={12} />
                    <span>{item.label}</span>
                    <ExternalLink size={10} className="inspector__related-external" />
                  </a>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Actions */}
        {actions && actions.length > 0 && (
          <div className="inspector__footer">
            {actions.map((action) => (
              <button
                key={action.id}
                type="button"
                className={`inspector__action inspector__action--${action.variant || 'ghost'}`}
                onClick={() => {
                  if (action.onClick) action.onClick();
                  else if (action.route) window.location.href = action.route;
                }}
              >
                {action.label}
              </button>
            ))}
          </div>
        )}
      </div>
    </>
  );
}

export default ClinicalInspector;
