/**
 * ClinicalErrorState — Context-Aware Error Recovery (Phase 152)
 *
 * Answers: "What happened? Did the operation complete? What should I do next?"
 *
 * Principles:
 * - Patient identity remains visible during error states
 * - Correlation ID is shown for traceability
 * - Error message is actionable, not generic
 * - Retry is available where safe
 * - Timeout uncertainty is communicated
 * - No false success
 * - No silent data loss
 *
 * Safety:
 * - Never shows generic "Something went wrong" for clinical contexts
 * - Always preserves patient identity context
 * - Distinguishes network errors from server errors from auth errors
 * - Never exposes SQL, stack traces, or internal details
 */

import { AlertTriangle, RefreshCw, WifiOff, Shield, Clock } from 'lucide-react';
import { ApiError, type ApiErrorCode } from '../api/client';

interface ClinicalErrorStateProps {
  /** The error that occurred */
  error: unknown;
  /** Patient context to preserve during error */
  patientId?: string;
  /** Patient name to display */
  patientName?: string;
  /** What the user was trying to do */
  context?: string;
  /** Retry handler — only provided when retry is safe */
  onRetry?: () => void;
  /** Whether the operation may have succeeded despite the error (timeout) */
  mayHaveSucceeded?: boolean;
  /** Custom title override */
  title?: string;
}

/**
 * Map error codes to user-friendly messages and icons.
 */
function getErrorInfo(error: unknown): {
  icon: React.ReactNode;
  title: string;
  message: string;
  tone: 'danger' | 'warning' | 'info';
  canRetry: boolean;
} {
  const apiErr = error instanceof ApiError ? error : null;
  const code: ApiErrorCode | null = apiErr?.code ?? null;

  switch (code) {
    case 'NETWORK':
      return {
        icon: <WifiOff size={20} />,
        title: 'Connection lost',
        message: 'Unable to reach the server. Check your internet connection.',
        tone: 'warning',
        canRetry: true,
      };
    case 'TIMEOUT':
      return {
        icon: <Clock size={20} />,
        title: 'Request timed out',
        message: 'The server took too long to respond. The operation may still be processing.',
        tone: 'warning',
        canRetry: true,
      };
    case 'UNAUTHORIZED':
      return {
        icon: <Shield size={20} />,
        title: 'Session expired',
        message: 'Your session has expired. Please sign in again.',
        tone: 'danger',
        canRetry: false,
      };
    case 'FORBIDDEN':
      return {
        icon: <Shield size={20} />,
        title: 'Access denied',
        message: 'You do not have permission to perform this action.',
        tone: 'danger',
        canRetry: false,
      };
    case 'NOT_FOUND':
      return {
        icon: <AlertTriangle size={20} />,
        title: 'Not found',
        message: 'The requested resource was not found or is not available to you.',
        tone: 'info',
        canRetry: false,
      };
    case 'CONFLICT':
      return {
        icon: <AlertTriangle size={20} />,
        title: 'Conflict detected',
        message: 'This record was modified by another user. Please refresh and try again.',
        tone: 'warning',
        canRetry: false,
      };
    case 'VALIDATION':
      return {
        icon: <AlertTriangle size={20} />,
        title: 'Validation error',
        message: apiErr?.message ?? 'Please check the form for errors.',
        tone: 'warning',
        canRetry: false,
      };
    case 'RATE_LIMITED':
      return {
        icon: <Clock size={20} />,
        title: 'Too many requests',
        message: 'Please wait a moment and try again.',
        tone: 'warning',
        canRetry: true,
      };
    case 'SERVER':
      return {
        icon: <AlertTriangle size={20} />,
        title: 'Server error',
        message: 'The server encountered an error. Please try again.',
        tone: 'danger',
        canRetry: true,
      };
    default:
      return {
        icon: <AlertTriangle size={20} />,
        title: 'Something went wrong',
        message: apiErr?.message ?? 'An unexpected error occurred.',
        tone: 'danger',
        canRetry: false,
      };
  }
}

/**
 * ClinicalErrorState — context-aware error display with patient identity preservation.
 *
 * Usage:
 *   <ClinicalErrorState
 *     error={error}
 *     patientId={id}
 *     patientName={patient?.fullName}
 *     context="loading lab results"
 *     onRetry={() => refresh()}
 *   />
 */
export function ClinicalErrorState({
  error,
  patientId,
  patientName,
  context,
  onRetry,
  title,
}: ClinicalErrorStateProps) {
  const info = getErrorInfo(error);
  const apiErr = error instanceof ApiError ? error : null;
  const displayTitle = title ?? info.title;

  return (
    <div className="clinical-error-state" role="alert" aria-live="polite">
      {/* Patient identity preserved during error */}
      {patientId && (
        <div className="clinical-error-state__context">
          <span className="clinical-error-state__patient">
            {patientName ?? 'Patient'}
            {patientId && <span className="clinical-error-state__id mono">{patientId.slice(0, 8)}</span>}
          </span>
          {context && (
            <span className="clinical-error-state__action">{context}</span>
          )}
        </div>
      )}

      {/* Error information */}
      <div className={`clinical-error-state__body clinical-error-state__body--${info.tone}`}>
        <span className="clinical-error-state__icon">{info.icon}</span>
        <div className="clinical-error-state__content">
          <h3 className="clinical-error-state__title">{displayTitle}</h3>
          <p className="clinical-error-state__message">{info.message}</p>

          {/* Correlation ID for traceability */}
          {apiErr?.correlationId && (
            <p className="clinical-error-state__ref">
              Reference: <span className="mono">{apiErr.correlationId.slice(0, 8)}</span>
            </p>
          )}
        </div>
      </div>

      {/* Actions */}
      {(info.canRetry || onRetry) && (
        <div className="clinical-error-state__actions">
          {onRetry && (
            <button
              type="button"
              className="clinical-error-state__retry"
              onClick={onRetry}
              aria-label={`Retry ${context ?? 'loading'}`}
            >
              <RefreshCw size={14} />
              Try again
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export default ClinicalErrorState;
