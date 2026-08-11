import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '../api/client';

interface FetchState<T> {
  data: T | null;
  loading: boolean;
  error: ApiError | null;
  refresh: () => Promise<void>;
}

/**
 * Minimal data-fetching hook. Loading/error/data are explicit; mutations are
 * server-confirmed (never client-only truth). A `key` change invalidates and
 * refetches — switching facility changes the key and clears stale data.
 */
export function useFetch<T>(fn: () => Promise<T>, key: unknown[]): FetchState<T> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const fnRef = useRef(fn);
  fnRef.current = fn;
  const keyStr = JSON.stringify(key);
  // Guards against out-of-order responses: when the key changes (e.g. the
  // booking dialog switches date, or the facility switches), a slow response
  // from the previous request must never overwrite the current one — stale
  // data is how a user could book a slot that is no longer offered.
  const genRef = useRef(0);

  const run = useCallback(async () => {
    const gen = ++genRef.current;
    setLoading(true);
    setError(null);
    try {
      const result = await fnRef.current();
      if (gen !== genRef.current) return; // superseded by a newer request
      setData(result);
    } catch (err) {
      if (gen !== genRef.current) return;
      setError(err instanceof ApiError ? err : new ApiError('UNKNOWN', err instanceof Error ? err.message : 'Request failed.'));
    } finally {
      if (gen === genRef.current) setLoading(false);
    }
  }, [keyStr]);

  useEffect(() => {
    void run();
  }, [run]);

  // Exactly ONE fetch per refresh: bumping a tick here would re-run the effect
  // and start a second, superseding request whose result then decides the UI
  // state (a race the queue-page check-in refresh hit). `run` is stable for a
  // given key, so the effect does not refire.
  const refresh = useCallback(async () => {
    await run();
  }, [run]);

  return { data, loading, error, refresh };
}
