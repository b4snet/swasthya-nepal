import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useAuth } from '../auth/AuthProvider';

/**
 * Tenant context — server-authoritative.
 *
 * The SPA NEVER derives authorization from local storage. The only source of
 * truth is the assignments payload the backend issued at login/refresh:
 * `{organizationId, organizationCode, facilityId, facilityName, roles}`.
 *
 * The selected facility is sent on every request as the X-Swasthya-Facility
 * *proposal*; the backend validates it against the principal's active
 * assignments and derives the tenant context (TENANCY.md V2 §7). Switching
 * context here simply changes the proposal; the backend decides.
 *
 * A user with exactly one facility is placed there automatically; with
 * several, they must choose. A platform-only user (no facility) sees the
 * platform surface only.
 */

interface TenantState {
  selectedFacilityId: string | null;
  selectedFacilityName: string | null;
  organizationId: string | null;
  organizationCode: string | null;
  /** Distinct facilities the principal is entitled to (server-derived). */
  facilities: Array<{ id: string; name: string; organizationId: string; organizationCode: string }>;
  roles: string[];
  hasRole: (...codes: string[]) => boolean;
  selectFacility: (facilityId: string) => void;
  /**
   * True when the app may render tenant-scoped pages: the principal has no
   * facility (platform-only) or a facility is selected. Pages must not fetch
   * before this is true — a tenant-less request would be a security smell and
   * a malformed URL.
   */
  ready: boolean;
}

const TenantContext = createContext<TenantState | null>(null);

export function TenantProvider({ children }: { children: ReactNode }) {
  const auth = useAuth();
  const assignments = Array.isArray(auth?.assignments) ? auth.assignments : [];
  const status = auth?.status ?? 'loading';
  const [selectedFacilityId, setSelectedFacilityId] = useState<string | null>(null);

  const facilities = useMemo(() => {
    const seen = new Map<string, { id: string; name: string; organizationId: string; organizationCode: string }>();
    for (const a of assignments) {
      if (a.facilityId && a.facilityName) {
        seen.set(a.facilityId, { id: a.facilityId, name: a.facilityName, organizationId: a.organizationId ?? '', organizationCode: a.organizationCode ?? '' });
      }
    }
    return [...seen.values()];
  }, [assignments]);

  const roles = useMemo(() => [...new Set(assignments.flatMap((a) => a.roles))], [assignments]);
  const hasRole = useCallback((...codes: string[]) => codes.some((c) => roles.includes(c)), [roles]);

  // Auto-select when the principal has exactly one facility; reset a stale
  // selection when assignments change (never show Tenant A's context for B).
  useEffect(() => {
    if (status !== 'authenticated') {
      setSelectedFacilityId(null);
      return;
    }
    if (facilities.length === 1) {
      setSelectedFacilityId(facilities[0].id);
      return;
    }
    setSelectedFacilityId((current) => {
      if (current && facilities.some((f) => f.id === current)) return current;
      return null;
    });
  }, [facilities, status]);

  const selected = facilities.find((f) => f.id === selectedFacilityId) ?? null;

  const selectFacility = useCallback((facilityId: string) => {
    setSelectedFacilityId(facilityId);
  }, []);

  const ready = facilities.length === 0 || selectedFacilityId !== null;

  const value = useMemo<TenantState>(
    () => ({
      selectedFacilityId: selected?.id ?? null,
      selectedFacilityName: selected?.name ?? null,
      organizationId: selected?.organizationId ?? null,
      organizationCode: selected?.organizationCode ?? null,
      facilities,
      roles,
      hasRole,
      selectFacility,
      ready,
    }),
    [selected, facilities, roles, hasRole, selectFacility, ready],
  );

  return <TenantContext.Provider value={value}>{children}</TenantContext.Provider>;
}

export function useTenant(): TenantState {
  const ctx = useContext(TenantContext);
  if (!ctx) throw new Error('useTenant must be used inside <TenantProvider>');
  return ctx;
}
