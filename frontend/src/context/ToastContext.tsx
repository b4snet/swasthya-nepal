import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import './toast.css';

export type ToastTone = 'success' | 'error' | 'warning' | 'info';

export interface Toast {
  id: string;
  tone: ToastTone;
  message: string;
  /** Auto-dismiss delay in ms. 0 = manual dismiss only. */
  duration: number;
}

interface ToastContextValue {
  /** Show a toast. Returns the toast id for programmatic dismissal. */
  toast: (tone: ToastTone, message: string, durationMs?: number) => string;
  /** Dismiss a specific toast by id. */
  dismiss: (id: string) => void;
  /** Convenience: show a success toast. */
  success: (message: string, durationMs?: number) => string;
  /** Convenience: show an error toast. */
  error: (message: string, durationMs?: number) => string;
}

const ToastContext = createContext<ToastContextValue | null>(null);

let counter = 0;
function nextId(): string {
  return `t-${++counter}`;
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const timers = useRef<Map<string, ReturnType<typeof setTimeout>>>(new Map());

  const dismiss = useCallback((id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
  }, []);

  const toast = useCallback(
    (tone: ToastTone, message: string, durationMs = 5000): string => {
      const id = nextId();
      const t: Toast = { id, tone, message, duration: durationMs };
      setToasts((prev) => [...prev, t]);
      if (durationMs > 0) {
        const timer = setTimeout(() => dismiss(id), durationMs);
        timers.current.set(id, timer);
      }
      return id;
    },
    [dismiss],
  );

  const success = useCallback((message: string, durationMs?: number) => toast('success', message, durationMs), [toast]);
  const error = useCallback((message: string, durationMs?: number) => toast('error', message, durationMs ?? 8000), [toast]);

  // Clean up all timers on unmount.
  useEffect(() => {
    const current = timers.current;
    return () => {
      for (const timer of current.values()) clearTimeout(timer);
      current.clear();
    };
  }, []);

  const value = useMemo(() => ({ toast, dismiss, success, error }), [toast, dismiss, success, error]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      <ToastContainer toasts={toasts} onDismiss={dismiss} />
    </ToastContext.Provider>
  );
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>');
  return ctx;
}

function ToastContainer({ toasts, onDismiss }: { toasts: Toast[]; onDismiss: (id: string) => void }) {
  if (toasts.length === 0) return null;
  return (
    <div className="toast-container" aria-live="polite" aria-relevant="additions removals">
      {toasts.map((t) => (
        <div key={t.id} className={`toast toast--${t.tone}`} role="status">
          <span className="toast__message">{t.message}</span>
          <button
            type="button"
            className="toast__close"
            onClick={() => onDismiss(t.id)}
            aria-label="Dismiss"
          >
            ×
          </button>
        </div>
      ))}
    </div>
  );
}
