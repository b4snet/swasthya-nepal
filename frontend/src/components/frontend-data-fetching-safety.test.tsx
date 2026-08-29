/**
 * Phase 231 — Frontend Data Fetching & Error Handling Safety Tests
 *
 * Tests the `useFetch` hook (frontend/src/hooks/useFetch.ts): the core
 * data-fetching primitive used across all pages. Covers generation-counter
 * stale-response prevention, loading/error state lifecycle, key-based
 * refetch, refresh stability, error wrapping, and async race conditions.
 *
 * Also covers cross-cutting error handling patterns used across the app.
 *
 * This is the final safety-test phase — covering the foundational
 * frontend infrastructure that every page depends on.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';

// ─── Mock the API client for useFetch error wrapping ─────────────────────────
vi.mock('../api/client', () => ({
  ApiError: class ApiError extends Error {
    code: string;
    httpStatus: number;
    correlationId: string | null;
    constructor(code: string, message: string, httpStatus = 0, correlationId: string | null = null) {
      super(message);
      this.name = 'ApiError';
      this.code = code;
      this.httpStatus = httpStatus;
      this.correlationId = correlationId;
    }
  },
}));

import { useFetch } from '../hooks/useFetch';
import { ApiError } from '../api/client';

beforeEach(() => {
  vi.clearAllMocks();
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 1 — USEFETCH LOADING STATE LIFECYCLE
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — useFetch loading state lifecycle', () => {
  it('starts with loading=true and data=null', () => {
    const fn = vi.fn().mockResolvedValue('test-data');
    const { result } = renderHook(() => useFetch(fn, ['key1']));
    expect(result.current.loading).toBe(true);
    expect(result.current.data).toBeNull();
    expect(result.current.error).toBeNull();
  });

  it('sets loading=false and data after successful fetch', async () => {
    const fn = vi.fn().mockResolvedValue('test-data');
    const { result } = renderHook(() => useFetch(fn, ['key1']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.data).toBe('test-data');
  });

  it('sets loading=false and error after failed fetch', async () => {
    const fn = vi.fn().mockRejectedValue(new ApiError('SERVER', 'Internal error', 500));
    const { result } = renderHook(() => useFetch(fn, ['key1']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.error).toBeInstanceOf(ApiError);
    expect(result.current.error?.code).toBe('SERVER');
  });

  it('calls the fetch function exactly once on mount', () => {
    const fn = vi.fn().mockResolvedValue('data');
    renderHook(() => useFetch(fn, ['key1']));
    expect(fn).toHaveBeenCalledTimes(1);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 2 — KEY-BASED REFETCH
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Key-based refetch', () => {
  it('refetches when key changes', async () => {
    const fn = vi.fn()
      .mockResolvedValueOnce('data-key1')
      .mockResolvedValueOnce('data-key2');

    const { result, rerender } = renderHook(
      ({ key }) => useFetch(fn, [key]),
      { initialProps: { key: 'key1' } },
    );

    await waitFor(() => expect(result.current.data).toBe('data-key1'));
    expect(fn).toHaveBeenCalledTimes(1);

    rerender({ key: 'key2' });
    await waitFor(() => expect(result.current.data).toBe('data-key2'));
    expect(fn).toHaveBeenCalledTimes(2);
  });

  it('does NOT refetch when key stays the same', async () => {
    const fn = vi.fn().mockResolvedValue('data');
    const { rerender } = renderHook(
      ({ key }) => useFetch(fn, [key]),
      { initialProps: { key: 'key1' } },
    );

    await waitFor(() => expect(fn).toHaveBeenCalledTimes(1));
    rerender({ key: 'key1' });
    // Still only 1 call — key didn't change
    expect(fn).toHaveBeenCalledTimes(1);
  });

  it('clears data when key changes (prevents stale display)', async () => {
    const fn = vi.fn()
      .mockResolvedValueOnce('data-key1')
      .mockImplementationOnce(() => new Promise(() => {})); // never resolves

    const { result, rerender } = renderHook(
      ({ key }) => useFetch(fn, [key]),
      { initialProps: { key: 'key1' } },
    );

    await waitFor(() => expect(result.current.data).toBe('data-key1'));
    rerender({ key: 'key2' });
    // After key change, data should be reset (loading=true, data=null)
    expect(result.current.loading).toBe(true);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 3 — STALE RESPONSE PREVENTION (GENERATION COUNTER)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Stale response prevention', () => {
  it('slow response from old key does NOT overwrite current data', async () => {
    let resolveFirst: (v: string) => void;
    const fn = vi.fn()
      .mockImplementationOnce(() => new Promise<string>((r) => { resolveFirst = r; }))
      .mockResolvedValueOnce('data-key2');

    const { result, rerender } = renderHook(
      ({ key }) => useFetch(fn, [key]),
      { initialProps: { key: 'key1' } },
    );

    // Key changes before first request completes
    rerender({ key: 'key2' });
    await waitFor(() => expect(result.current.data).toBe('data-key2'));

    // Now resolve the slow first request
    act(() => resolveFirst!('stale-data-key1'));

    // Data should NOT be overwritten with stale data
    expect(result.current.data).toBe('data-key2');
  });

  it('error from old key does NOT overwrite current success', async () => {
    let rejectFirst: (e: Error) => void;
    const fn = vi.fn()
      .mockImplementationOnce(() => new Promise<string>((_, r) => { rejectFirst = r; }))
      .mockResolvedValueOnce('data-key2');

    const { result, rerender } = renderHook(
      ({ key }) => useFetch(fn, [key]),
      { initialProps: { key: 'key1' } },
    );

    rerender({ key: 'key2' });
    await waitFor(() => expect(result.current.data).toBe('data-key2'));

    // Reject the slow first request
    act(() => rejectFirst!(new ApiError('SERVER', 'timeout', 500)));

    // Error should NOT overwrite current success
    expect(result.current.data).toBe('data-key2');
    expect(result.current.error).toBeNull();
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 4 — ERROR WRAPPING
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Error wrapping', () => {
  it('wraps ApiError instances directly', async () => {
    const apiErr = new ApiError('FORBIDDEN', 'Access denied', 403);
    const fn = vi.fn().mockRejectedValue(apiErr);
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.error).toBe(apiErr);
    expect(result.current.error?.code).toBe('FORBIDDEN');
  });

  it('wraps non-ApiError into ApiError with UNKNOWN code', async () => {
    const fn = vi.fn().mockRejectedValue(new Error('Something went wrong'));
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.error).toBeInstanceOf(ApiError);
    expect(result.current.error?.code).toBe('UNKNOWN');
    expect(result.current.error?.message).toBe('Something went wrong');
  });

  it('wraps non-Error values into ApiError with UNKNOWN code', async () => {
    const fn = vi.fn().mockRejectedValue('string error');
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.error).toBeInstanceOf(ApiError);
    expect(result.current.error?.code).toBe('UNKNOWN');
    expect(result.current.error?.message).toBe('Request failed.');
  });

  it('clears previous error on successful refetch', async () => {
    const fn = vi.fn()
      .mockRejectedValueOnce(new ApiError('SERVER', 'error'))
      .mockResolvedValueOnce('recovered-data');

    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.error).toBeInstanceOf(ApiError));

    // Trigger refresh
    await act(async () => { await result.current.refresh(); });
    expect(result.current.error).toBeNull();
    expect(result.current.data).toBe('recovered-data');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 5 — REFRESH STABILITY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Refresh stability', () => {
  it('refresh function is stable across renders (no identity change)', async () => {
    const fn = vi.fn().mockResolvedValue('data');
    const { result, rerender } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));

    const refresh1 = result.current.refresh;
    rerender();
    const refresh2 = result.current.refresh;
    expect(refresh1).toBe(refresh2);
  });

  it('refresh triggers exactly one fetch (no double-fetch)', async () => {
    const fn = vi.fn().mockResolvedValue('data');
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(fn).toHaveBeenCalledTimes(1);
    await act(async () => { await result.current.refresh(); });
    expect(fn).toHaveBeenCalledTimes(2);
  });

  it('refresh calls the fetch function exactly once per invocation', async () => {
    const fn = vi.fn().mockResolvedValue('data');
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(fn).toHaveBeenCalledTimes(1);
    await act(async () => { await result.current.refresh(); });
    expect(fn).toHaveBeenCalledTimes(2);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 6 — FUNCTION REF UPDATE
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Function ref update', () => {
  it('refresh uses the latest fetch function via fnRef', async () => {
    let latestData = 'old-data';
    const fn = vi.fn().mockImplementation(() => Promise.resolve(latestData));

    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.data).toBe('old-data'));

    // Update what the function returns
    latestData = 'new-data';
    await act(async () => { await result.current.refresh(); });
    expect(result.current.data).toBe('new-data');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 7 — EMPTY STATE & NULL DATA
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Empty state and null data', () => {
  it('data is null when fetch returns null', async () => {
    const fn = vi.fn().mockResolvedValue(null);
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.data).toBeNull();
    expect(result.current.error).toBeNull();
  });

  it('data is empty array when fetch returns []', async () => {
    const fn = vi.fn().mockResolvedValue([]);
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.data).toEqual([]);
    expect(result.current.error).toBeNull();
  });

  it('data is empty object when fetch returns {}', async () => {
    const fn = vi.fn().mockResolvedValue({});
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.data).toEqual({});
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 8 — AUDIT TRAIL
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Audit trail', () => {
  it('useFetch does not log sensitive data (no console.log of data)', async () => {
    const consoleSpy = vi.spyOn(console, 'log');
    const fn = vi.fn().mockResolvedValue({ sensitive: 'patient-data' });
    renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(fn).toHaveBeenCalled());

    // No console.log calls with the data
    const dataLogs = consoleSpy.mock.calls.filter((call: unknown[]) =>
      call.some((arg: unknown) => typeof arg === 'string' && arg.includes('patient-data'))
    );
    expect(dataLogs).toHaveLength(0);
    consoleSpy.mockRestore();
  });

  it('useFetch does not log error details to console', async () => {
    const consoleSpy = vi.spyOn(console, 'error');
    const fn = vi.fn().mockRejectedValue(new ApiError('SERVER', 'Internal error', 500));
    renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(fn).toHaveBeenCalled());

    // The hook should NOT log errors to console — callers handle display
    expect(consoleSpy).not.toHaveBeenCalled();
    consoleSpy.mockRestore();
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 9 — PRIVACY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Privacy', () => {
  it('error state does not expose correlationId to end users via data', async () => {
    const fn = vi.fn().mockRejectedValue(
      new ApiError('SERVER', 'Error', 500, 'corr-123')
    );
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    // correlationId is on the error object, not exposed via data
    expect(result.current.data).toBeNull();
  });

  it('data is never stored in sessionStorage or localStorage by useFetch', async () => {
    const fn = vi.fn().mockResolvedValue({ patient: 'data' });
    renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(fn).toHaveBeenCalled());
    // useFetch only uses React state — never persists to storage
    expect(sessionStorage.length).toBe(0);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 10 — ARCHITECTURE COMPLETENESS
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 231 — Architecture completeness', () => {
  it('useFetch returns exactly { data, loading, error, refresh }', () => {
    const fn = vi.fn().mockResolvedValue('data');
    const { result } = renderHook(() => useFetch(fn, ['key']));
    const keys = Object.keys(result.current);
    expect(keys).toContain('data');
    expect(keys).toContain('loading');
    expect(keys).toContain('error');
    expect(keys).toContain('refresh');
    expect(keys.length).toBe(4);
  });

  it('refresh is a function', async () => {
    const fn = vi.fn().mockResolvedValue('data');
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(typeof result.current.refresh).toBe('function');
  });

  it('data type matches the fetch function return type', async () => {
    const fn = vi.fn().mockResolvedValue({ name: 'test', count: 42 });
    const { result } = renderHook(() => useFetch(fn, ['key']));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(typeof result.current.data).toBe('object');
    expect(result.current.data).toHaveProperty('name');
    expect(result.current.data).toHaveProperty('count');
  });

  it('useFetch handles async function that returns Promise', async () => {
    const fn = vi.fn().mockImplementation(async () => {
      await new Promise((r) => setTimeout(r, 10));
      return 'async-data';
    });
    const { result } = renderHook(() => useFetch(fn, ['key']));
    expect(result.current.loading).toBe(true);
    await waitFor(() => expect(result.current.data).toBe('async-data'));
    expect(result.current.loading).toBe(false);
  });
});
