/**
 * useClinicalWorkSources — Shared Clinical Work Data Fetching (Phase 150)
 *
 * Single source of truth for fetching facility-wide clinical work data.
 * Both ClinicalWorkQueue and ClinicalCommandSurface use this hook
 * instead of independently fetching the same 5 API sources.
 *
 * Data sources:
 *   - appointmentsApi.list()        → today's appointments
 *   - appointmentsApi.queue()       → waiting patients
 *   - referralsApi.list()           → pending referrals
 *   - criticalValueApi.list()       → critical lab values
 *   - radiologyApi.queue()          → radiology worklist
 *
 * Safety:
 *   - Reads only; never mutates on load
 *   - Facility-scoped by selectedFacilityId
 *   - All data is normalized into arrays before returning
 *   - No clinical priority inference — only authoritative status
 */

import { useMemo } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from './useFetch';
import { appointmentsApi, referralsApi } from '../api/clinical';
import { criticalValueApi, radiologyApi } from '../api/laboratory';

export interface ClinicalWorkSources {
  /** Raw appointment data (normalized to array) */
  appointments: any[];
  /** Raw queue entry data (normalized to array) */
  queueEntries: any[];
  /** Raw referral data (normalized to array) */
  referrals: any[];
  /** Raw critical value data (normalized to array) */
  criticalValues: any[];
  /** Raw radiology queue data (normalized to array) */
  radiologyQueue: any[];
  /** Whether all sources are still loading */
  isLoading: boolean;
  /** Whether any source returned an error */
  hasError: boolean;
  /** Refresh all sources */
  refreshAll: () => void;
}

/**
 * Fetch all clinical work data sources for the current facility.
 * Returns normalized arrays ready for work-item derivation.
 */
export function useClinicalWorkSources(): ClinicalWorkSources {
  const { selectedFacilityId: fac } = useTenant();

  const appointments = useFetch(
    () => appointmentsApi.list({ facilityId: fac }),
    [fac],
  );

  const queue = useFetch(
    () => appointmentsApi.queue({ facilityId: fac }),
    [fac],
  );

  const referrals = useFetch(
    () => referralsApi.list({ facilityId: fac }),
    [fac],
  );

  const criticalValues = useFetch(
    () => criticalValueApi.list(fac),
    [fac],
  );

  const radiologyQueue = useFetch(
    () => radiologyApi.queue(fac),
    [fac],
  );

  // Normalize all data sources to arrays
  const normalizedData = useMemo(() => {
    const apptsData = Array.isArray(appointments.data) ? appointments.data : [];
    const queueData = Array.isArray(queue.data) ? queue.data : [];
    const refData = Array.isArray(referrals.data)
      ? referrals.data
      : ((referrals.data as any)?.data ?? []);
    const cvData = Array.isArray(criticalValues.data) ? criticalValues.data : [];
    const radData = Array.isArray(radiologyQueue.data) ? radiologyQueue.data : [];

    return {
      appointments: apptsData,
      queueEntries: queueData,
      referrals: refData,
      criticalValues: cvData,
      radiologyQueue: radData,
    };
  }, [appointments.data, queue.data, referrals.data, criticalValues.data, radiologyQueue.data]);

  const isLoading = appointments.loading && queue.loading && referrals.loading
    && criticalValues.loading && radiologyQueue.loading;

  const hasError = !!(appointments.error || queue.error || referrals.error
    || criticalValues.error || radiologyQueue.error);

  const refreshAll = useMemo(() => () => {
    appointments.refresh();
    queue.refresh();
    referrals.refresh();
    criticalValues.refresh();
    radiologyQueue.refresh();
  }, [appointments.refresh, queue.refresh, referrals.refresh, criticalValues.refresh, radiologyQueue.refresh]);

  return {
    ...normalizedData,
    isLoading,
    hasError,
    refreshAll,
  };
}

/**
 * Role-aware filter for clinical work items.
 * Uses canonical role constants from clinical-work-types.
 */
export function filterByRole<T extends { visibleTo: string[] }>(
  items: T[],
  hasRole: (role: string) => boolean,
): T[] {
  return items.filter((item) => {
    if (item.visibleTo.length === 0) return true;
    return item.visibleTo.some((r) => hasRole(r));
  });
}
