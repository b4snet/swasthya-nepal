import { useEffect, useId, useRef } from 'react';
import type { CSSProperties, ReactNode, ButtonHTMLAttributes, InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { ApiError } from '../api/client';
import './ui.css';

/* ------------------------------------------------------------------ Button */

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'danger-outline';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  loading?: boolean;
  full?: boolean;
  size?: 'sm' | 'md';
}

export function Button({ variant = 'primary', loading = false, full = false, size = 'md', children, disabled, className = '', ...rest }: ButtonProps) {
  return (
    <button
      className={`btn btn--${variant} ${full ? 'btn--full' : ''} ${size === 'sm' ? 'btn--sm' : ''} ${className}`}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...rest}
    >
      {loading && <span className="btn__spinner" aria-hidden="true" />}
      {children}
    </button>
  );
}

/* ------------------------------------------------------------------ Input */

interface FieldShellProps {
  label: string;
  htmlFor?: string;
  hint?: string;
  error?: string | null;
  required?: boolean;
  children: ReactNode;
}

export function FieldShell({ label, htmlFor, hint, error, required, children }: FieldShellProps) {
  const id = useId();
  const controlId = htmlFor ?? id;
  return (
    <div className={`field ${error ? 'field--error' : ''}`}>
      <label className="field__label" htmlFor={controlId}>
        {label}
        {required && <span className="field__required" aria-hidden="true"> *</span>}
      </label>
      {children}
      {hint && !error && <p className="field__hint">{hint}</p>}
      {error && (
        <p className="field__error" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  error?: string | null;
  hint?: string;
}

export function Input({ label, error, hint, required, id, className = '', ...rest }: InputProps) {
  const autoId = useId();
  const controlId = id ?? autoId;
  return (
    <FieldShell label={label} htmlFor={controlId} hint={hint} error={error} required={required}>
      <input id={controlId} className={`input ${error ? 'input--error' : ''} ${className}`} aria-invalid={error ? true : undefined} required={required} {...rest} />
    </FieldShell>
  );
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string;
  error?: string | null;
  hint?: string;
  children: ReactNode;
}

export function Select({ label, error, hint, required, id, className = '', children, ...rest }: SelectProps) {
  const autoId = useId();
  const controlId = id ?? autoId;
  return (
    <FieldShell label={label} htmlFor={controlId} hint={hint} error={error} required={required}>
      <select id={controlId} className={`input ${error ? 'input--error' : ''} ${className}`} aria-invalid={error ? true : undefined} required={required} {...rest}>
        {children}
      </select>
    </FieldShell>
  );
}

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label: string;
  error?: string | null;
}

export function Textarea({ label, error, required, id, className = '', ...rest }: TextareaProps) {
  const autoId = useId();
  const controlId = id ?? autoId;
  return (
    <FieldShell label={label} htmlFor={controlId} error={error} required={required}>
      <textarea id={controlId} className={`input input--area ${error ? 'input--error' : ''} ${className}`} aria-invalid={error ? true : undefined} required={required} {...rest} />
    </FieldShell>
  );
}

/* ------------------------------------------------------------------ Card */

interface CardProps {
  title?: string;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
  style?: CSSProperties;
  onClick?: () => void;
}

export function Card({ title, action, children, className = '', style, onClick }: CardProps) {
  const Tag = onClick ? 'button' : 'div';
  return (
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    <Tag onClick={onClick} className={`card ${onClick ? 'card--clickable' : ''} ${className}`} style={onClick ? ({ textAlign: 'left', width: '100%', font: 'inherit', color: 'inherit', ...style } as CSSProperties) : style}>
      {(title || action) && (
        <div className="card__head">
          {title && <h3 className="card__title">{title}</h3>}
          {action}
        </div>
      )}
      {children}
    </Tag>
  );
}

/* ------------------------------------------------------------------ Status */

export type StatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

export function StatusChip({ tone = 'neutral', label, struck = false }: { tone?: StatusTone; label: string; struck?: boolean }) {
  return (
    <span className={`status status--${tone} ${struck ? 'status--struck' : ''}`}>
      <span aria-hidden="true" className="status__dot" />
      {label}
    </span>
  );
}

const APPOINTMENT_STATUS: Record<string, { tone: StatusTone; label: string }> = {
  booked: { tone: 'neutral', label: 'Booked' },
  checked_in: { tone: 'info', label: 'Checked in' },
  in_consultation: { tone: 'info', label: 'In consultation' },
  completed: { tone: 'success', label: 'Completed' },
  cancelled: { tone: 'neutral', label: 'Cancelled' },
  no_show: { tone: 'neutral', label: 'No-show' },
};

export function AppointmentStatus({ status }: { status: string }) {
  const s = APPOINTMENT_STATUS[status] ?? { tone: 'neutral' as StatusTone, label: status };
  return <StatusChip tone={s.tone} label={s.label} struck={status === 'cancelled'} />;
}

const FINANCIAL_STATUS: Record<string, { tone: StatusTone; label: string }> = {
  draft: { tone: 'neutral', label: 'Draft' },
  issued: { tone: 'info', label: 'Issued' },
  partially_paid: { tone: 'warning', label: 'Partially paid' },
  paid: { tone: 'success', label: 'Paid' },
  voided: { tone: 'neutral', label: 'Voided' },
};

export function FinancialStatus({ status }: { status: string }) {
  const s = FINANCIAL_STATUS[status] ?? { tone: 'neutral' as StatusTone, label: status };
  return <StatusChip tone={s.tone} label={s.label} struck={status === 'voided'} />;
}

/* ------------------------------------------------------------------ States */

export function Spinner({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="state" role="status" aria-live="polite">
      <span className="spinner" aria-hidden="true" />
      <span>{label}</span>
    </div>
  );
}

/* ------------------------------------------------------------------ Skeletons */

/** A single shimmer bar. */
function SkeletonBar({ className = '', style }: { className?: string; style?: React.CSSProperties }) {
  return <div className={`skeleton ${className}`} style={style} aria-hidden="true" />;
}

/** Skeleton for 3 stat cards in a row. */
export function SkeletonStats() {
  return (
    <div className="grid grid--3" aria-label="Loading statistics" role="status">
      {[1, 2, 3].map((i) => (
        <div key={i} className="skeleton--stat">
          <SkeletonBar className="skeleton--text-sm" style={{ width: '50%' }} />
          <SkeletonBar className="skeleton--heading" style={{ width: '30%' }} />
        </div>
      ))}
    </div>
  );
}

/** Skeleton for a card with a title bar and content lines. */
export function SkeletonCard({ rows = 3 }: { rows?: number }) {
  return (
    <div className="skeleton--card" aria-label="Loading content" role="status">
      <SkeletonBar className="skeleton--heading" style={{ width: '40%' }} />
      {Array.from({ length: rows }, (_, i) => (
        <SkeletonBar key={i} className="skeleton--text" style={{ width: `${70 + (i % 3) * 10}%` }} />
      ))}
    </div>
  );
}

/** Skeleton for a data table with header + N rows. */
export function SkeletonTable({ rows = 5, cols = 4 }: { rows?: number; cols?: number }) {
  return (
    <div className="skeleton--table" aria-label="Loading table" role="status">
      <div className="skeleton--table-row">
        {Array.from({ length: cols }, (_, i) => (
          <SkeletonBar key={i} className="skeleton--text-sm" style={{ flex: i === 0 ? '2' : '1', height: 8 }} />
        ))}
      </div>
      {Array.from({ length: rows }, (_, r) => (
        <div key={r} className="skeleton--table-row">
          {Array.from({ length: cols }, (_, i) => (
            <SkeletonBar key={i} className="skeleton--text" style={{ flex: i === 0 ? '2' : '1', width: `${50 + ((r + i) % 3) * 15}%` }} />
          ))}
        </div>
      ))}
    </div>
  );
}

/** Full-page skeleton for a page with header + cards. */
export function SkeletonPage() {
  return (
    <div className="page" aria-label="Loading page" role="status">
      <div className="page__head">
        <div className="page__title">
          <SkeletonBar className="skeleton--heading" style={{ width: '200px' }} />
          <SkeletonBar className="skeleton--text-sm" style={{ width: '300px' }} />
        </div>
      </div>
      <SkeletonStats />
      <SkeletonCard rows={4} />
    </div>
  );
}

export function EmptyState({ title, body, action }: { title: string; body?: string; action?: ReactNode }) {
  return (
    <div className="state state--empty">
      <h3>{title}</h3>
      {body && <p className="muted">{body}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({ error, onRetry, title = 'Something went wrong' }: { error: unknown; onRetry?: () => void; title?: string }) {
  const apiErr = error instanceof ApiError ? error : null;
  const message = apiErr?.message ?? (error instanceof Error ? error.message : 'An unexpected error occurred.');
  return (
    <div className="state state--error" role="alert">
      <h3>{title}</h3>
      <p>{message}</p>
      {apiErr?.correlationId && (
        <p className="caption">Reference: <span className="mono">{apiErr.correlationId.slice(0, 8)}</span></p>
      )}
      {onRetry && (
        <div className="mt-4">
          <Button variant="secondary" onClick={onRetry}>
            Try again
          </Button>
        </div>
      )}
    </div>
  );
}

export function Alert({ tone = 'info', children }: { tone?: StatusTone; children: ReactNode }) {
  return (
    <div className={`alert alert--${tone}`} role={tone === 'danger' ? 'alert' : tone === 'warning' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}

/* ------------------------------------------------------------------ Dialog */

interface DialogProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  footer?: ReactNode;
}

export function Dialog({ open, onClose, title, children, footer }: DialogProps) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    ref.current?.querySelector<HTMLElement>('input,button,[tabindex]')?.focus();
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  return (
    <div className="dialog-backdrop" onClick={onClose}>
      <div
        ref={ref}
        className="dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="dialog-title"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 id="dialog-title" className="dialog__title">
          {title}
        </h2>
        <div className="dialog__body">{children}</div>
        {footer && <div className="dialog__footer">{footer}</div>}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ Nav bits */

export function Breadcrumbs({ items }: { items: Array<{ label: string; to?: string }> }) {
  return (
    <nav aria-label="Breadcrumb" className="breadcrumbs">
      <ol>
        {items.map((item, i) => (
          <li key={i} aria-current={i === items.length - 1 ? 'page' : undefined}>
            {item.to ? <a href={item.to}>{item.label}</a> : <span>{item.label}</span>}
          </li>
        ))}
      </ol>
    </nav>
  );
}

export function Tabs({ tabs, active, onChange }: { tabs: Array<{ id: string; label: string }>; active: string; onChange: (id: string) => void }) {
  return (
    <div role="tablist" className="tabs" aria-label="Sections">
      {tabs.map((t) => (
        <button
          key={t.id}
          role="tab"
          aria-selected={active === t.id}
          className={`tabs__tab ${active === t.id ? 'tabs__tab--active' : ''}`}
          onClick={() => onChange(t.id)}
        >
          {t.label}
        </button>
      ))}
    </div>
  );
}

export function Stat({ value, label }: { value: ReactNode; label: string }) {
  return (
    <div className="stat">
      <span className="stat__value">{value}</span>
      <span className="stat__label">{label}</span>
    </div>
  );
}

/* ------------------------------------------------------------------ Money */

export function money(minor: number | null | undefined, currency = 'NPR'): string {
  const value = (minor ?? 0) / 100;
  return `${currency} ${value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}
