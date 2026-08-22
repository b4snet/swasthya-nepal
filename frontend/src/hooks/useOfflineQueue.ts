import { useCallback, useEffect, useState } from 'react';

/**
 * Offline Action Queue
 *
 * Stores pending actions in IndexedDB while offline and replays them
 * when connectivity is restored. Each action has an id, type, payload,
 * timestamp, and status (pending | syncing | failed | completed).
 *
 * Does NOT allow unrestricted offline clinical mutation — only
 * pre-approved action types can be queued.
 */

const DB_NAME = 'swasthya-offline';
const DB_VERSION = 1;
const STORE_NAME = 'action-queue';

export interface OfflineAction {
  id: string;
  type: string;
  payload: Record<string, unknown>;
  createdAt: string;
  status: 'pending' | 'syncing' | 'failed' | 'completed';
  retries: number;
  lastError?: string;
  syncedAt?: string;
}

/** Only pre-approved action types can be queued offline. */
const ALLOWED_TYPES = new Set([
  'vitals.record',
  'nursing.task.complete',
  'nursing.alert.acknowledge',
  'patient.search',
  'barcode.scan',
  'notification.read',
]);

function openDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'id' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

let dbPromise: Promise<IDBDatabase> | null = null;
function getDB() {
  if (!dbPromise) dbPromise = openDB();
  return dbPromise;
}

async function getAll(): Promise<OfflineAction[]> {
  const db = await getDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readonly');
    const store = tx.objectStore(STORE_NAME);
    const req = store.getAll();
    req.onsuccess = () => resolve(req.result as OfflineAction[]);
    req.onerror = () => reject(req.error);
  });
}

async function put(action: OfflineAction): Promise<void> {
  const db = await getDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite');
    tx.objectStore(STORE_NAME).put(action);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

async function remove(id: string): Promise<void> {
  const db = await getDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite');
    tx.objectStore(STORE_NAME).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

function generateId(): string {
  return `oq_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
}

export interface UseOfflineQueueReturn {
  /** All queued actions (pending + syncing + failed + completed) */
  actions: OfflineAction[];
  /** Count of pending actions */
  pendingCount: number;
  /** Whether currently syncing */
  isSyncing: boolean;
  /** Whether device is online */
  isOnline: boolean;
  /** Queue an action for offline processing. Returns the action ID. */
  enqueue: (type: string, payload: Record<string, unknown>) => string | null;
  /** Force retry all failed actions */
  retryAll: () => void;
  /** Clear completed actions from queue */
  clearCompleted: () => void;
  /** Remove a single action */
  removeAction: (id: string) => void;
}

/**
 * Hook to manage offline action queue.
 *
 * Usage:
 * ```tsx
 * const { enqueue, pendingCount, isOnline } = useOfflineQueue();
 *
 * // Record vitals — works offline
 * const actionId = enqueue('vitals.record', {
 *   patientId, temperatureCelsius, heartRateBpm, ...
 * });
 * ```
 */
export function useOfflineQueue(): UseOfflineQueueReturn {
  const [actions, setActions] = useState<OfflineAction[]>([]);
  const [isSyncing, setIsSyncing] = useState(false);
  const [isOnline, setIsOnline] = useState(navigator.onLine);

  // Load actions on mount
  useEffect(() => {
    void getAll().then(setActions).catch(() => {});
  }, []);

  // Listen for online/offline
  useEffect(() => {
    const onOnline = () => setIsOnline(true);
    const onOffline = () => setIsOnline(false);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    return () => {
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []);

  // Auto-sync when coming online
  useEffect(() => {
    if (!isOnline || isSyncing) return;
    const pending = actions.filter((a) => a.status === 'pending' || a.status === 'failed');
    if (pending.length === 0) return;

    const syncAll = async () => {
      setIsSyncing(true);
      for (const action of pending) {
        try {
          const updated = { ...action, status: 'syncing' as const };
          await put(updated);
          setActions((prev) => prev.map((a) => (a.id === action.id ? updated : a)));

          // In production, this would dispatch to the actual API
          // For now, simulate successful sync
          await new Promise((r) => setTimeout(r, 100));

          const completed: OfflineAction = {
            ...action,
            status: 'completed',
            syncedAt: new Date().toISOString(),
          };
          await put(completed);
          setActions((prev) => prev.map((a) => (a.id === action.id ? completed : a)));
        } catch (err) {
          const failed: OfflineAction = {
            ...action,
            status: 'failed',
            retries: action.retries + 1,
            lastError: err instanceof Error ? err.message : 'Sync failed',
          };
          await put(failed);
          setActions((prev) => prev.map((a) => (a.id === action.id ? failed : a)));
        }
      }
      setIsSyncing(false);
    };

    void syncAll();
  }, [isOnline, isSyncing, actions]);

  const enqueue = useCallback((type: string, payload: Record<string, unknown>): string | null => {
    if (!ALLOWED_TYPES.has(type)) {
      console.warn(`[OfflineQueue] Action type "${type}" is not approved for offline queue.`);
      return null;
    }

    const id = generateId();
    const action: OfflineAction = {
      id,
      type,
      payload,
      createdAt: new Date().toISOString(),
      status: navigator.onLine ? 'syncing' : 'pending',
      retries: 0,
    };

    void put(action);
    setActions((prev) => [...prev, action]);

    // If online, trigger immediate sync
    if (navigator.onLine) {
      setIsOnline(true); // triggers sync effect
    }

    return id;
  }, []);

  const retryAll = useCallback(() => {
    setActions((prev) =>
      prev.map((a) => (a.status === 'failed' ? { ...a, status: 'pending' as const } : a)),
    );
    // Trigger sync effect
    setIsOnline((prev) => {
      if (prev) setIsSyncing(false);
      return prev;
    });
  }, []);

  const clearCompleted = useCallback(() => {
    const completed = actions.filter((a) => a.status === 'completed');
    for (const a of completed) {
      void remove(a.id);
    }
    setActions((prev) => prev.filter((a) => a.status !== 'completed'));
  }, [actions]);

  const removeAction = useCallback((id: string) => {
    void remove(id);
    setActions((prev) => prev.filter((a) => a.id !== id));
  }, []);

  return {
    actions,
    pendingCount: actions.filter((a) => a.status === 'pending' || a.status === 'failed').length,
    isSyncing,
    isOnline,
    enqueue,
    retryAll,
    clearCompleted,
    removeAction,
  };
}
