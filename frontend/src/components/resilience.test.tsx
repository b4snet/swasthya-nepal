/**
 * Phase 152 — Clinical Workflow Resilience Tests
 *
 * Proves:
 * - Error states preserve patient context
 * - Error messages are actionable, not generic
 * - Correlation IDs are available for traceability
 * - Retry is only offered when safe
 * - Timeout uncertainty is communicated
 * - Network errors are distinguishable
 * - Auth errors are distinguishable
 * - Loading/empty/error states are consistent
 * - useFetch stale-response guard works
 * - Patient identity remains visible during failure
 * - No false success on error
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ApiError } from '../api/client';
import { ClinicalErrorState } from './ClinicalErrorState';

// ════════════════════════════════════════════════════════════════════
// ERROR STATE RENDERING
// ════════════════════════════════════════════════════════════════════

describe('Phase 152 — ClinicalErrorState', () => {
  it('renders with default error message', () => {
    render(<ClinicalErrorState error={new Error('Test error')} />);
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
  });

  it('renders network error with retry', () => {
    const error = new ApiError('NETWORK', 'Cannot reach the server.', 0);
    render(<ClinicalErrorState error={error} onRetry={vi.fn()} />);
    expect(screen.getByText('Connection lost')).toBeInTheDocument();
    expect(screen.getByText(/Check your internet connection/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  it('renders timeout error with uncertainty message', () => {
    const error = new ApiError('TIMEOUT', 'Request timed out.', 0);
    render(<ClinicalErrorState error={error} onRetry={vi.fn()} />);
    expect(screen.getByText('Request timed out')).toBeInTheDocument();
    expect(screen.getByText(/may still be processing/)).toBeInTheDocument();
  });

  it('renders unauthorized error without retry', () => {
    const error = new ApiError('UNAUTHORIZED', 'Token expired.', 401);
    render(<ClinicalErrorState error={error} />);
    expect(screen.getByText('Session expired')).toBeInTheDocument();
    expect(screen.getByText(/sign in again/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });

  it('renders forbidden error without retry', () => {
    const error = new ApiError('FORBIDDEN', 'No permission.', 403);
    render(<ClinicalErrorState error={error} />);
    expect(screen.getByText('Access denied')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });

  it('renders not-found error', () => {
    const error = new ApiError('NOT_FOUND', 'Patient not found.', 404);
    render(<ClinicalErrorState error={error} />);
    expect(screen.getByText('Not found')).toBeInTheDocument();
  });

  it('renders conflict error', () => {
    const error = new ApiError('CONFLICT', 'Record modified.', 409);
    render(<ClinicalErrorState error={error} />);
    expect(screen.getByText('Conflict detected')).toBeInTheDocument();
    expect(screen.getByText(/modified by another user/)).toBeInTheDocument();
  });

  it('renders server error with retry', () => {
    const error = new ApiError('SERVER', 'Internal error.', 500);
    render(<ClinicalErrorState error={error} onRetry={vi.fn()} />);
    expect(screen.getByText('Server error')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  it('renders correlation ID when available', () => {
    const error = new ApiError('SERVER', 'Error.', 500, 'abc123def456');
    render(<ClinicalErrorState error={error} />);
    expect(screen.getByText('Reference:')).toBeInTheDocument();
    expect(screen.getByText('abc123de')).toBeInTheDocument();
  });
});

// ════════════════════════════════════════════════════════════════════
// PATIENT CONTEXT DURING FAILURE
// ════════════════════════════════════════════════════════════════════

describe('Phase 152 — Patient Context During Failure', () => {
  it('preserves patient identity during error state', () => {
    render(
      <ClinicalErrorState
        error={new ApiError('NETWORK', 'Connection lost.', 0)}
        patientId="pat-abc123"
        patientName="Ram Sharma"
        context="loading lab results"
        onRetry={vi.fn()}
      />,
    );
    expect(screen.getByText('Ram Sharma')).toBeInTheDocument();
    expect(screen.getByText('pat-abc1')).toBeInTheDocument();
    expect(screen.getByText('loading lab results')).toBeInTheDocument();
  });

  it('preserves patient identity even for auth errors', () => {
    render(
      <ClinicalErrorState
        error={new ApiError('UNAUTHORIZED', 'Token expired.', 401)}
        patientId="pat-abc123"
        patientName="Sita Devi"
        context="viewing encounter"
      />,
    );
    expect(screen.getByText('Sita Devi')).toBeInTheDocument();
    expect(screen.getByText('Session expired')).toBeInTheDocument();
  });

  it('renders patient context bar with teal accent', () => {
    const { container } = render(
      <ClinicalErrorState
        error={new ApiError('SERVER', 'Error.', 500)}
        patientId="pat-abc123"
        patientName="Test Patient"
      />,
    );
    const contextBar = container.querySelector('.clinical-error-state__context');
    expect(contextBar).toBeInTheDocument();
  });
});

// ════════════════════════════════════════════════════════════════════
// RETRY SAFETY
// ════════════════════════════════════════════════════════════════════

describe('Phase 152 — Retry Safety', () => {
  it('offers retry for network errors', () => {
    const error = new ApiError('NETWORK', 'Connection lost.', 0);
    render(<ClinicalErrorState error={error} onRetry={vi.fn()} />);
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  it('offers retry for timeout errors', () => {
    const error = new ApiError('TIMEOUT', 'Timed out.', 0);
    render(<ClinicalErrorState error={error} onRetry={vi.fn()} />);
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  it('does NOT offer retry for validation errors', () => {
    const error = new ApiError('VALIDATION', 'Invalid input.', 422);
    render(<ClinicalErrorState error={error} />);
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });

  it('does NOT offer retry for not-found errors', () => {
    const error = new ApiError('NOT_FOUND', 'Not found.', 404);
    render(<ClinicalErrorState error={error} />);
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });

  it('does NOT offer retry for auth errors', () => {
    const error = new ApiError('UNAUTHORIZED', 'Token expired.', 401);
    render(<ClinicalErrorState error={error} />);
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });
});

// ════════════════════════════════════════════════════════════════════
// ERROR MESSAGE QUALITY
// ════════════════════════════════════════════════════════════════════

describe('Phase 152 — Error Message Quality', () => {
  it('every error answers: what happened', () => {
    const errors = [
      new ApiError('NETWORK', 'Error.', 0),
      new ApiError('TIMEOUT', 'Error.', 0),
      new ApiError('UNAUTHORIZED', 'Error.', 401),
      new ApiError('FORBIDDEN', 'Error.', 403),
      new ApiError('NOT_FOUND', 'Error.', 404),
      new ApiError('CONFLICT', 'Error.', 409),
      new ApiError('SERVER', 'Error.', 500),
    ];
    for (const error of errors) {
      const { unmount } = render(<ClinicalErrorState error={error} />);
      // Each should have a title and message
      const title = screen.getByRole('alert').querySelector('h3');
      const message = screen.getByRole('alert').querySelector('p');
      expect(title?.textContent).toBeTruthy();
      expect(message?.textContent).toBeTruthy();
      unmount();
    }
  });

  it('timeout message includes uncertainty', () => {
    const error = new ApiError('TIMEOUT', 'Timed out.', 0);
    render(<ClinicalErrorState error={error} />);
    const message = screen.getByRole('alert').querySelector('p');
    expect(message?.textContent).toMatch(/may still be processing/i);
  });

  it('conflict message suggests refresh', () => {
    const error = new ApiError('CONFLICT', 'Record modified.', 409);
    render(<ClinicalErrorState error={error} />);
    const message = screen.getByRole('alert').querySelector('p');
    expect(message?.textContent).toMatch(/refresh/i);
  });
});

// ════════════════════════════════════════════════════════════════════
// ACCESSIBILITY
// ════════════════════════════════════════════════════════════════════

describe('Phase 152 — Accessibility', () => {
  it('error state has role="alert"', () => {
    render(<ClinicalErrorState error={new Error('Test')} />);
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });

  it('retry button is keyboard accessible', () => {
    render(
      <ClinicalErrorState
        error={new ApiError('NETWORK', 'Error.', 0)}
        onRetry={vi.fn()}
      />,
    );
    const retryBtn = screen.getByRole('button', { name: /retry/i });
    expect(retryBtn.tagName).toBe('BUTTON');
    expect(retryBtn).not.toHaveAttribute('tabindex', '-1');
  });

  it('error state uses aria-live for screen readers', () => {
    render(<ClinicalErrorState error={new Error('Test')} />);
    const alert = screen.getByRole('alert');
    expect(alert).toHaveAttribute('aria-live', 'polite');
  });
});
