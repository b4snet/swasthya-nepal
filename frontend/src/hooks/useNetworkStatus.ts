import { useEffect, useState } from 'react';

interface NetworkStatus {
  online: boolean;
  effectiveType: string | null;
  downlink: number | null;
  rtt: number | null;
}

/**
 * Detects online/offline status and network quality where supported.
 * Useful for showing offline indicators and adjusting retry behavior.
 */
export function useNetworkStatus(): NetworkStatus {
  const [status, setStatus] = useState<NetworkStatus>({
    online: navigator.onLine,
    effectiveType: null,
    downlink: null,
    rtt: null,
  });

  useEffect(() => {
    const handleOnline = () => setStatus((prev) => ({ ...prev, online: true }));
    const handleOffline = () => setStatus((prev) => ({ ...prev, online: false }));

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    // Network Information API (Chrome, Edge, Android)
    const conn = (navigator as unknown as { connection?: { effectiveType?: string; downlink?: number; rtt?: number; addEventListener?: (type: string, handler: () => void) => void } }).connection;
    if (conn) {
      const updateFromConnection = () => {
        setStatus((prev) => ({
          ...prev,
          effectiveType: conn.effectiveType ?? null,
          downlink: conn.downlink ?? null,
          rtt: conn.rtt ?? null,
        }));
      };
      updateFromConnection();
      conn.addEventListener?.('change', updateFromConnection);
    }

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  return status;
}
