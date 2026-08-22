/**
 * Centralized frontend access control.
 *
 * This is the SINGLE SOURCE OF TRUTH for all client-side authorization checks.
 * Backend `authorize:` gates and RLS remain authoritative (SECURITY.md §27).
 * Hiding a screen here never grants anything; showing one never bypasses a
 * backend check.
 *
 * Every component that needs to check access should use this hook rather than
 * scattering `if (role === 'doctor')` checks throughout the codebase.
 */

import { useAuth } from '../auth/AuthProvider';
import { useTenant } from '../context/TenantContext';

// ── Role constants (mirror backend seeded catalog) ──
export const ROLES = {
  SUPERADMIN: 'superadmin',
  SUPPORT_AGENT: 'support_agent',
  ORG_ADMIN: 'org_admin',
  ORG_FINANCE: 'org_finance',
  HOSPITAL_ADMIN: 'hospital_admin',
  BRANCH_MANAGER: 'branch_manager',
  RECEPTIONIST: 'receptionist',
  BILLING_CLERK: 'billing_clerk',
  DOCTOR: 'doctor',
  NURSE: 'nurse',
  PHARMACIST: 'pharmacist',
  LAB_TECHNICIAN: 'lab_technician',
} as const;

export type RoleCode = (typeof ROLES)[keyof typeof ROLES];

// ── Permission codes ──
export const PERMISSIONS = {
  // Patient
  PATIENT_VIEW: 'patient:view',
  PATIENT_CREATE: 'patient:create',
  PATIENT_EDIT: 'patient:edit',
  PATIENT_DELETE: 'patient:delete',
  // Appointment
  APPOINTMENT_VIEW: 'appointment:view',
  APPOINTMENT_CREATE: 'appointment:create',
  APPOINTMENT_EDIT: 'appointment:edit',
  APPOINTMENT_CANCEL: 'appointment:cancel',
  // Encounter
  ENCOUNTER_VIEW: 'encounter:view',
  ENCOUNTER_CREATE: 'encounter:create',
  ENCOUNTER_SIGN: 'encounter:sign',
  // Prescription
  PRESCRIPTION_VIEW: 'prescription:view',
  PRESCRIPTION_CREATE: 'prescription:create',
  PRESCRIPTION_VERIFY: 'prescription:verify',
  PRESCRIPTION_DISPENSE: 'prescription:dispense',
  // Lab
  LAB_VIEW: 'lab:view',
  LAB_ORDER: 'lab:order',
  LAB_RESULT: 'lab:result',
  LAB_VERIFY: 'lab:verify',
  // Radiology
  RADIOLOGY_VIEW: 'radiology:view',
  RADIOLOGY_ORDER: 'radiology:order',
  RADIOLOGY_REPORT: 'radiology:report',
  // Billing
  BILLING_VIEW: 'billing:view',
  BILLING_CREATE: 'billing:create',
  BILLING_PAY: 'billing:pay',
  BILLING_REFUND: 'billing:refund',
  // Finance
  FINANCE_VIEW: 'finance:view',
  FINANCE_BUDGET: 'finance:budget',
  FINANCE_EXPENSE: 'finance:expense',
  // Inventory
  INVENTORY_VIEW: 'inventory:view',
  INVENTORY_MANAGE: 'inventory:manage',
  // Procurement
  PROCUREMENT_VIEW: 'procurement:view',
  PROCUREMENT_CREATE: 'procurement:create',
  PROCUREMENT_APPROVE: 'procurement:approve',
  // Administration
  ADMIN_USERS: 'admin:users',
  ADMIN_ROLES: 'admin:roles',
  ADMIN_DEPARTMENTS: 'admin:departments',
  ADMIN_SETTINGS: 'admin:settings',
  ADMIN_BRANDING: 'admin:branding',
  // Audit
  AUDIT_VIEW: 'audit:view',
  // Documents
  DOCUMENT_VIEW: 'document:view',
  DOCUMENT_CREATE: 'document:create',
  // Notifications
  NOTIFICATION_VIEW: 'notification:view',
} as const;

export type PermissionCode = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

// ── Module keys ──
export const MODULES_ENABLED = {
  HOSPITAL: 'hospital',
  CLINICAL: 'clinical',
  PHARMACY: 'pharmacy',
  LABORATORY: 'laboratory',
  RADIOLOGY: 'radiology',
  BLOOD_BANK: 'blood_bank',
  FINANCE: 'finance',
  PROCUREMENT: 'procurement',
  REPORTS: 'reports',
  COMMUNICATIONS: 'communications',
  ADMINISTRATION: 'administration',
  PATIENT_PORTAL: 'patient_portal',
  EMERGENCY: 'emergency',
  ICU: 'icu',
  OT: 'ot',
  NURSING: 'nursing',
} as const;

// ── Role → default module mapping ──
const ROLE_DEFAULT_MODULE: Record<string, string> = {
  [ROLES.DOCTOR]: 'clinical',
  [ROLES.NURSE]: 'clinical',
  [ROLES.PHARMACIST]: 'pharmacy',
  [ROLES.LAB_TECHNICIAN]: 'laboratory',
  [ROLES.BILLING_CLERK]: 'finance',
  [ROLES.RECEPTIONIST]: 'hospital',
  [ROLES.HOSPITAL_ADMIN]: 'hospital',
  [ROLES.ORG_ADMIN]: 'administration',
  [ROLES.SUPERADMIN]: 'administration',
  [ROLES.BRANCH_MANAGER]: 'hospital',
  [ROLES.ORG_FINANCE]: 'finance',
};

// ── Access hook ──
export function useAccess() {
  const { user } = useAuth();
  const { hasRole: tenantHasRole } = useTenant();

  /**
   * Check if the user has a specific role.
   */
  const hasRole = (role: RoleCode): boolean => {
    return tenantHasRole(role);
  };

  /**
   * Check if the user has any of the given roles.
   */
  const hasAnyRole = (...roles: RoleCode[]): boolean => {
    return roles.some((r) => tenantHasRole(r));
  };

  /**
   * Check if the user has a specific permission.
   * Uses the role-based permission mapping from the backend seeder.
   */
  const can = (permission: PermissionCode): boolean => {
    // Superadmin can do everything
    if (tenantHasRole(ROLES.SUPERADMIN)) return true;

    // Role → permission mapping (simplified client-side mirror of backend)
    const rolePermissions: Record<string, PermissionCode[]> = {
      [ROLES.ORG_ADMIN]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_CREATE, PERMISSIONS.PATIENT_EDIT,
        PERMISSIONS.APPOINTMENT_VIEW, PERMISSIONS.APPOINTMENT_CREATE, PERMISSIONS.APPOINTMENT_EDIT, PERMISSIONS.APPOINTMENT_CANCEL,
        PERMISSIONS.ENCOUNTER_VIEW, PERMISSIONS.ENCOUNTER_CREATE,
        PERMISSIONS.PRESCRIPTION_VIEW, PERMISSIONS.PRESCRIPTION_CREATE,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_ORDER,
        PERMISSIONS.RADIOLOGY_VIEW, PERMISSIONS.RADIOLOGY_ORDER,
        PERMISSIONS.BILLING_VIEW, PERMISSIONS.BILLING_CREATE, PERMISSIONS.BILLING_PAY,
        PERMISSIONS.FINANCE_VIEW,
        PERMISSIONS.INVENTORY_VIEW, PERMISSIONS.INVENTORY_MANAGE,
        PERMISSIONS.PROCUREMENT_VIEW, PERMISSIONS.PROCUREMENT_CREATE,
        PERMISSIONS.ADMIN_USERS, PERMISSIONS.ADMIN_ROLES, PERMISSIONS.ADMIN_DEPARTMENTS, PERMISSIONS.ADMIN_SETTINGS, PERMISSIONS.ADMIN_BRANDING,
        PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.DOCUMENT_VIEW, PERMISSIONS.DOCUMENT_CREATE,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.HOSPITAL_ADMIN]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_CREATE, PERMISSIONS.PATIENT_EDIT,
        PERMISSIONS.APPOINTMENT_VIEW, PERMISSIONS.APPOINTMENT_CREATE, PERMISSIONS.APPOINTMENT_EDIT,
        PERMISSIONS.ENCOUNTER_VIEW, PERMISSIONS.ENCOUNTER_CREATE,
        PERMISSIONS.PRESCRIPTION_VIEW, PERMISSIONS.PRESCRIPTION_CREATE,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_ORDER,
        PERMISSIONS.RADIOLOGY_VIEW, PERMISSIONS.RADIOLOGY_ORDER,
        PERMISSIONS.BILLING_VIEW, PERMISSIONS.BILLING_CREATE,
        PERMISSIONS.FINANCE_VIEW,
        PERMISSIONS.INVENTORY_VIEW, PERMISSIONS.INVENTORY_MANAGE,
        PERMISSIONS.PROCUREMENT_VIEW, PERMISSIONS.PROCUREMENT_CREATE,
        PERMISSIONS.ADMIN_USERS, PERMISSIONS.ADMIN_ROLES, PERMISSIONS.ADMIN_DEPARTMENTS,
        PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.DOCUMENT_VIEW, PERMISSIONS.DOCUMENT_CREATE,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.DOCTOR]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.APPOINTMENT_VIEW,
        PERMISSIONS.ENCOUNTER_VIEW, PERMISSIONS.ENCOUNTER_CREATE, PERMISSIONS.ENCOUNTER_SIGN,
        PERMISSIONS.PRESCRIPTION_VIEW, PERMISSIONS.PRESCRIPTION_CREATE,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_ORDER,
        PERMISSIONS.RADIOLOGY_VIEW, PERMISSIONS.RADIOLOGY_ORDER,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.NURSE]: [
        PERMISSIONS.PATIENT_VIEW,
        PERMISSIONS.APPOINTMENT_VIEW,
        PERMISSIONS.ENCOUNTER_VIEW,
        PERMISSIONS.PRESCRIPTION_VIEW,
        PERMISSIONS.LAB_VIEW,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.PHARMACIST]: [
        PERMISSIONS.PATIENT_VIEW,
        PERMISSIONS.PRESCRIPTION_VIEW, PERMISSIONS.PRESCRIPTION_VERIFY, PERMISSIONS.PRESCRIPTION_DISPENSE,
        PERMISSIONS.INVENTORY_VIEW, PERMISSIONS.INVENTORY_MANAGE,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.LAB_TECHNICIAN]: [
        PERMISSIONS.PATIENT_VIEW,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_RESULT,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.BILLING_CLERK]: [
        PERMISSIONS.PATIENT_VIEW,
        PERMISSIONS.BILLING_VIEW, PERMISSIONS.BILLING_CREATE, PERMISSIONS.BILLING_PAY,
        PERMISSIONS.FINANCE_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.RECEPTIONIST]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_CREATE,
        PERMISSIONS.APPOINTMENT_VIEW, PERMISSIONS.APPOINTMENT_CREATE, PERMISSIONS.APPOINTMENT_EDIT,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.ORG_FINANCE]: [
        PERMISSIONS.PATIENT_VIEW,
        PERMISSIONS.BILLING_VIEW, PERMISSIONS.BILLING_CREATE, PERMISSIONS.BILLING_PAY, PERMISSIONS.BILLING_REFUND,
        PERMISSIONS.FINANCE_VIEW, PERMISSIONS.FINANCE_BUDGET, PERMISSIONS.FINANCE_EXPENSE,
        PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.BRANCH_MANAGER]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.APPOINTMENT_VIEW,
        PERMISSIONS.ENCOUNTER_VIEW,
        PERMISSIONS.BILLING_VIEW,
        PERMISSIONS.FINANCE_VIEW,
        PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
    };

    // Check all assigned roles
    const assignments = (user as any)?.assignments ?? [];
    for (const assignment of assignments) {
      const roles: string[] = assignment.roles ?? [];
      for (const role of roles) {
        const perms = rolePermissions[role] ?? [];
        if (perms.includes(permission)) return true;
      }
    }
    return false;
  };

  /**
   * Get the default landing module for the current user's primary role.
   */
  const getDefaultModule = (): string => {
    const assignments = (user as any)?.assignments ?? [];
    for (const assignment of assignments) {
      const roles: string[] = assignment.roles ?? [];
      for (const role of roles) {
        if (ROLE_DEFAULT_MODULE[role]) return ROLE_DEFAULT_MODULE[role];
      }
    }
    return 'hospital';
  };

  /**
   * Get the user's display name (email prefix or full name).
   */
  const getDisplayName = (): string => {
    return user?.email?.split('@')[0] ?? 'User';
  };

  /**
   * Get the user's initials for avatar.
   */
  const getInitials = (): string => {
    const name = getDisplayName();
    return name.slice(0, 2).toUpperCase();
  };

  /**
   * Check if the user is a platform-level admin (superadmin/support).
   */
  const isPlatformAdmin = (): boolean => {
    return hasAnyRole(ROLES.SUPERADMIN, ROLES.SUPPORT_AGENT);
  };

  /**
   * Check if the user is a hospital-level admin.
   */
  const isHospitalAdmin = (): boolean => {
    return hasAnyRole(ROLES.HOSPITAL_ADMIN, ROLES.ORG_ADMIN, ROLES.BRANCH_MANAGER);
  };

  /**
   * Check if the user is clinical staff (doctor, nurse).
   */
  const isClinical = (): boolean => {
    return hasAnyRole(ROLES.DOCTOR, ROLES.NURSE);
  };

  /**
   * Check if the user is pharmacy staff.
   */
  const isPharmacy = (): boolean => {
    return hasAnyRole(ROLES.PHARMACIST);
  };

  /**
   * Check if the user is lab staff.
   */
  const isLab = (): boolean => {
    return hasAnyRole(ROLES.LAB_TECHNICIAN);
  };

  /**
   * Check if the user is finance/billing staff.
   */
  const isFinance = (): boolean => {
    return hasAnyRole(ROLES.BILLING_CLERK, ROLES.ORG_FINANCE);
  };

  return {
    hasRole,
    hasAnyRole,
    can,
    getDefaultModule,
    getDisplayName,
    getInitials,
    isPlatformAdmin,
    isHospitalAdmin,
    isClinical,
    isPharmacy,
    isLab,
    isFinance,
    user,
  };
}
