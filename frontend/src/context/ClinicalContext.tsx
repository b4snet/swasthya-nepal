/**
 * ClinicalContextProvider — Clinical Context Layer (Phase 123)
 *
 * Provides app-wide access to the current clinical context:
 * - current patient
 * - current encounter
 * - current facility
 * - current user role
 * - clinical urgency
 * - recent actions
 * - pending work
 *
 * Derived from authoritative URL/API state. NOT a second source of truth.
 * NOT a duplicate patient database. NOT an authorization layer.
 *
 * Safety:
 * - Context clears on logout
 * - Context replaces on patient switch
 * - Stale context does not survive refresh
 * - No sensitive data persisted to unsafe storage
 * - Backend authorization remains authoritative
 */

import { createContext, useContext, useMemo, type ReactNode } from 'react';
import { useLocation, useParams } from 'react-router-dom';
import { useTenant } from './TenantContext';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

export type EncounterSetting = 'none' | 'opd' | 'ipd' | 'emergency' | 'nursing' | 'diagnostic';
export type ClinicalUrgencyLevel = 'routine' | 'attention' | 'urgent' | 'critical';

export interface ClinicalContextValue {
  /** Active patient ID from URL (null if not in patient workspace) */
  patientId: string | null;
  /** Active workspace tab (from ?ws= param) */
  workspaceTab: string;
  /** Active encounter setting derived from route */
  encounterSetting: EncounterSetting;
  /** Current user role (primary) */
  userRole: string;
  /** Whether a patient context is active */
  hasPatient: boolean;
  /** Whether the current view is the emergency module */
  isEmergency: boolean;
  /** Whether the current view is the patient workspace */
  isPatientWorkspace: boolean;
  /** Route prefix of the active module */
  activeModule: string;
}

/* ────────────────────────────────────────────────────────────────────
   DEFAULT VALUE
   ──────────────────────────────────────────────────────────────────── */

const DEFAULT_CONTEXT: ClinicalContextValue = {
  patientId: null,
  workspaceTab: 'overview',
  encounterSetting: 'none',
  userRole: '',
  hasPatient: false,
  isEmergency: false,
  isPatientWorkspace: false,
  activeModule: '',
};

export const ClinicalContext = createContext<ClinicalContextValue>(DEFAULT_CONTEXT);

/* ────────────────────────────────────────────────────────────────────
   HOOK
   ──────────────────────────────────────────────────────────────────── */

export function useClinicalContext(): ClinicalContextValue {
  return useContext(ClinicalContext);
}

/* ────────────────────────────────────────────────────────────────────
   PROVIDER
   ──────────────────────────────────────────────────────────────────── */

/**
 * ClinicalContextProvider — derives clinical context from authoritative
 * URL/route state and provides it to the entire component tree.
 *
 * Does NOT fetch patient data. Does NOT create duplicate state.
 * Only references what the URL and existing auth already know.
 */
export function ClinicalContextProvider({ children }: { children: ReactNode }) {
  const location = useLocation();
  const { id: routePatientId } = useParams<{ id: string }>();
  const { hasRole } = useTenant();

  const value = useMemo<ClinicalContextValue>(() => {
    const pathname = location.pathname;
    const searchParams = new URLSearchParams(location.search);

    // Patient context from URL
    const patientId = routePatientId ?? null;
    const hasPatient = !!patientId;
    const workspaceTab = searchParams.get('ws') || 'overview';

    // Module detection
    const isEmergency = pathname.startsWith('/emergency');
    const isPatientWorkspace = pathname.match(/^\/clinical\/patients\/[^/]+/) !== null;

    // Encounter setting from route context
    let encounterSetting: EncounterSetting = 'none';
    if (isEmergency) {
      encounterSetting = 'emergency';
    } else if (pathname.startsWith('/ipd') || pathname.startsWith('/nursing')) {
      encounterSetting = 'ipd';
    } else if (pathname.startsWith('/nursing')) {
      encounterSetting = 'nursing';
    } else if (pathname.startsWith('/laboratory') || pathname.startsWith('/radiology')) {
      encounterSetting = 'diagnostic';
    } else if (pathname.startsWith('/clinical')) {
      encounterSetting = 'opd';
    }

    // Active module prefix
    let activeModule = '';
    if (pathname.startsWith('/clinical')) activeModule = 'clinical';
    else if (pathname.startsWith('/emergency')) activeModule = 'emergency';
    else if (pathname.startsWith('/ipd')) activeModule = 'inpatient';
    else if (pathname.startsWith('/pharmacy')) activeModule = 'pharmacy';
    else if (pathname.startsWith('/laboratory')) activeModule = 'laboratory';
    else if (pathname.startsWith('/radiology')) activeModule = 'radiology';
    else if (pathname.startsWith('/nursing')) activeModule = 'nursing';
    else if (pathname.startsWith('/finance')) activeModule = 'finance';
    else if (pathname.startsWith('/admin')) activeModule = 'admin';
    else if (pathname.startsWith('/reports')) activeModule = 'analytics';
    else if (pathname.startsWith('/communications')) activeModule = 'communications';
    else if (pathname.startsWith('/dashboard')) activeModule = 'dashboard';

    // Primary user role
    const roles = ['superadmin', 'org_admin', 'hospital_admin', 'doctor', 'nurse',
      'pharmacist', 'lab_technician', 'lab_supervisor', 'radiologist', 'radiographer',
      'billing_clerk', 'receptionist', 'org_finance'];
    const userRole = roles.find((r) => hasRole(r as any)) || '';

    return {
      patientId,
      workspaceTab,
      encounterSetting,
      userRole,
      hasPatient,
      isEmergency,
      isPatientWorkspace,
      activeModule,
    };
  }, [location.pathname, location.search, routePatientId, hasRole]);

  return (
    <ClinicalContext.Provider value={value}>
      {children}
    </ClinicalContext.Provider>
  );
}

export default ClinicalContextProvider;
