import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useAuth } from '../auth/AuthProvider';

/**
 * Tenant context — server-authoritative.
 *
 * The SPA NEVER derives authorization from local storage. The only source of
 * truth is the assignments payload the backend issued at login/refresh:
 * `{organizationId, organizationCode, facilityId, facilityName, roles}`.
 *
 * The selected facility is persisted in sessionStorage for refresh survival
 * and sent on every request as the X-Swasthya-Facility *proposal*; the backend
 * validates it against the principal's active assignments and derives the
 * tenant context (TENANCY.md V2 §7). Switching context here simply changes
 * the proposal; the backend decides.
 *
 * Auth bootstrap order:
 *   SESSION → USER → ASSIGNMENTS → ORGANIZATIONS → FACILITIES → ROLES →
 *   PERMISSIONS → MODULE ENTITLEMENTS → CURRENT FACILITY → READY
 *
 * A user with exactly one facility is placed there automatically; with
 * several, they must choose. A platform-only user (no facility) sees the
 * platform surface only.
 */

const FACILITY_STORAGE_KEY = 'swasthya.selectedFacilityId';

/**
 * Persist the selected facility to sessionStorage so it survives hard refresh.
 * Not in localStorage (session-only, like an active login session).
 * Never persists org/role/permission data — only a UI convenience preference.
 */
function persistFacility(id: string | null): void {
  try {
    if (id) {
      sessionStorage.setItem(FACILITY_STORAGE_KEY, id);
    } else {
      sessionStorage.removeItem(FACILITY_STORAGE_KEY);
    }
  } catch {
    // sessionStorage unavailable (SSR, private browsing edge case) — silent.
  }
}

function readPersistedFacility(): string | null {
  try {
    return sessionStorage.getItem(FACILITY_STORAGE_KEY) || null;
  } catch {
    return null;
  }
}

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
   * True when the app may render tenant-scoped pages: the auth session is
   * resolved AND the principal has either no facility (platform-only) or a
   * valid facility is selected. Pages must not fetch before this is true.
   *
   * FALSE during the loading phase (before assignments are resolved) to
   * prevent premature rendering with unresolved context.
   */
  ready: boolean;
  /** True while auth session is being restored from a persisted token. */
  loading: boolean;
}

const TenantContext = createContext<TenantState | null>(null);

export function TenantProvider({ children }: { children: ReactNode }) {
  const auth = useAuth();
  const assignments = Array.isArray(auth?.assignments) ? auth.assignments : [];
  const status = auth?.status ?? 'loading';
  const [selectedFacilityId, setSelectedFacilityId] = useState<string | null>(null);
  const initialized = useRef(false);

  // ── Derive facilities from assignments ──
  const facilities = useMemo(() => {
    const seen = new Map<string, { id: string; name: string; organizationId: string; organizationCode: string }>();
    for (const a of assignments) {
      if (a.facilityId && a.facilityName) {
        seen.set(a.facilityId, {
          id: a.facilityId,
          name: a.facilityName,
          organizationId: a.organizationId ?? '',
          organizationCode: a.organizationCode ?? '',
        });
      }
    }
    return [...seen.values()];
  }, [assignments]);

  // ── Derive organization context from assignments ──
  // Org-level users (e.g. org_admin) have organizationId on their assignment
  // but no facilityId, so the facility-derived path yields null.
  const orgContext = useMemo(() => {
    for (const a of assignments) {
      if (a.organizationId) {
        return { organizationId: a.organizationId, organizationCode: a.organizationCode ?? '' };
      }
    }
    return null;
  }, [assignments]);

  // ── Derive roles from assignments ──
  const roles = useMemo(() => [...new Set(assignments.flatMap((a) => a.roles))], [assignments]);
  const hasRole = useCallback((...codes: string[]) => codes.some((c) => roles.includes(c)), [roles]);

  // ── Facility selection logic ──
  // On first auth resolution: restore from sessionStorage, auto-select sole
  // facility, or validate that the persisted choice is still authorized.
  // On assignment change (e.g. facility access revoked): re-validate.
  useEffect(() => {
    if (status !== 'authenticated') {
      setSelectedFacilityId(null);
      initialized.current = false;
      persistFacility(null);
      return;
    }

    // First time auth resolves — try sessionStorage first.
    if (!initialized.current) {
      initialized.current = true;
      const persisted = readPersistedFacility();
      if (persisted && facilities.some((f) => f.id === persisted)) {
        // Restored facility is still authorized.
        setSelectedFacilityId(persisted);
        return;
      }
      // No valid persisted choice. Auto-select if sole facility.
      if (facilities.length === 1) {
        setSelectedFacilityId(facilities[0].id);
        persistFacility(facilities[0].id);
        return;
      }
      setSelectedFacilityId(null);
      return;
    }

    // Subsequent assignment changes: validate current selection is still valid.
    setSelectedFacilityId((current) => {
      if (current && facilities.some((f) => f.id === current)) return current;
      // Selection invalidated. Auto-select if sole facility.
      if (facilities.length === 1) {
        persistFacility(facilities[0].id);
        return facilities[0].id;
      }
      persistFacility(null);
      return null;
    });
  }, [facilities, status]);

  // ── Derived state ──
  const selected = facilities.find((f) => f.id === selectedFacilityId) ?? null;

  const selectFacility = useCallback((facilityId: string) => {
    setSelectedFacilityId(facilityId);
    persistFacility(facilityId);
  }, []);

  // ready: true only when auth is resolved AND either:
  //   (a) no facilities (platform-only) — the principal is authorized for platform mode
  //   (b) a facility is selected — the principal has a valid tenant context
  // During 'loading': false — prevents premature rendering with empty context.
  const ready = status === 'authenticated' && (facilities.length === 0 || selectedFacilityId !== null);

  const value = useMemo<TenantState>(
    () => ({
      selectedFacilityId: selected?.id ?? null,
      selectedFacilityName: selected?.name ?? null,
      // Prefer facility-derived org context (more specific); fall back to
      // assignment-derived org context for org-level users without a facility.
      organizationId: selected?.organizationId || orgContext?.organizationId || null,
      organizationCode: selected?.organizationCode || orgContext?.organizationCode || null,
      facilities,
      roles,
      hasRole,
      selectFacility,
      ready,
      loading: status === 'loading',
    }),
    [selected, orgContext, facilities, roles, hasRole, selectFacility, ready, status],
  );

  return <TenantContext.Provider value={value}>{children}</TenantContext.Provider>;
}

export function useTenant(): TenantState {
  const ctx = useContext(TenantContext);
  if (!ctx) throw new Error('useTenant must be used inside <TenantProvider>');
  return ctx;
}
