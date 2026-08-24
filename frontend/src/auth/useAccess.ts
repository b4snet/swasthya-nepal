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
  LAB_SUPERVISOR: 'lab_supervisor',
  RADIOGRAPHER: 'radiographer',
  RADIOLOGIST: 'radiologist',
} as const;

export type RoleCode = (typeof ROLES)[keyof typeof ROLES];

// ── Permission codes (MUST match backend RolePermissionSeeder exactly) ──
export const PERMISSIONS = {
  // Patient
  PATIENT_VIEW: 'patient:view',
  PATIENT_REGISTER: 'patient:register',
  PATIENT_UPDATE: 'patient:update',
  PATIENT_SEARCH: 'patient:search',
  PATIENT_MERGE: 'patient:merge',
  // Appointment
  APPOINTMENT_VIEW: 'appointment:view',
  APPOINTMENT_BOOK: 'appointment:book',
  APPOINTMENT_CHECKIN: 'appointment:checkin',
  APPOINTMENT_CANCEL: 'appointment:cancel',
  // Encounter
  ENCOUNTER_VIEW: 'encounter:view',
  ENCOUNTER_CREATE: 'encounter:create',
  ENCOUNTER_DOCUMENT: 'encounter:document',
  ENCOUNTER_PRESCRIBE: 'encounter:prescribe',
  ENCOUNTER_SIGN: 'encounter:sign',
  // Medication / Pharmacy
  MEDICATION_VIEW: 'medication:view',
  MEDICATION_MANAGE: 'medication:manage',
  PHARMACY_VIEW: 'pharmacy:view',
  PHARMACY_STOCK: 'pharmacy:stock',
  PHARMACY_DISPENSE: 'pharmacy:dispense',
  // Lab
  LAB_VIEW: 'lab:view',
  LAB_ORDER: 'lab:order',
  LAB_MANAGE: 'lab:manage',
  LAB_RESULT_ENTRY: 'lab:result_entry',
  LAB_VERIFY: 'lab:verify',
  LAB_ACKNOWLEDGE: 'lab:acknowledge',
  LAB_ESCALATE: 'lab:escalate',
  LAB_CORRECT: 'lab:correct',
  // Radiology
  RADIOLOGY_VIEW: 'radiology:view',
  RADIOLOGY_ORDER: 'radiology:order',
  RADIOLOGY_SCHEDULE: 'radiology:schedule',
  RADIOLOGY_PERFORM: 'radiology:perform',
  RADIOLOGY_REPORT: 'radiology:report',
  RADIOLOGY_VERIFY: 'radiology:verify',
  RADIOLOGY_MANAGE: 'radiology:manage',
  // Billing
  BILLING_VIEW: 'billing:view',
  BILLING_INVOICE: 'billing:invoice',
  BILLING_COLLECT: 'billing:collect',
  BILLING_REFUND: 'billing:refund',
  BILLING_REFUND_APPROVE: 'billing:refund-approve',
  BILLING_RECONCILE: 'billing:reconcile',
  BILLING_VOID: 'billing:void',
  // Insurance / Claims
  INSURANCE_VIEW: 'insurance:view',
  INSURANCE_MANAGE: 'insurance:manage',
  INSURANCE_CLAIM: 'insurance:claim',
  INSURANCE_SETTLE: 'insurance:settle',
  PAYER_VIEW: 'payer:view',
  PAYER_MANAGE: 'payer:manage',
  // Finance
  ANALYTICS_VIEW: 'analytics:view',
  ANALYTICS_MANAGE: 'analytics:manage',
  REPORTS_RUN: 'reports:run',
  REPORTS_SCHEDULE: 'reports:schedule',
  REPORTS_EXPORT: 'reports:export',
  // Inventory
  INVENTORY_TRANSFER: 'inventory:transfer',
  INVENTORY_ADJUST_REQUEST: 'inventory:adjust-request',
  INVENTORY_ADJUST_APPROVE: 'inventory:adjust-approve',
  // Procurement
  PROCUREMENT_VIEW: 'procurement:view',
  PROCUREMENT_REQUEST: 'procurement:request',
  PROCUREMENT_APPROVE: 'procurement:approve',
  PROCUREMENT_ORDER: 'procurement:order',
  PROCUREMENT_RECEIVE: 'procurement:receive',
  PROCUREMENT_CONTRACT: 'procurement:contract',
  // Administration
  USER_VIEW: 'user:view',
  USER_CREATE: 'user:create',
  ROLE_VIEW: 'role:view',
  ROLE_ASSIGN: 'role:assign',
  ROLE_REVOKE: 'role:revoke',
  DEPARTMENT_VIEW: 'department:view',
  DEPARTMENT_MANAGE: 'department:manage',
  SETTINGS_VIEW: 'settings:view',
  SETTINGS_MANAGE: 'settings:manage',
  BRANDING_VIEW: 'branding:view',
  BRANDING_MANAGE: 'branding:manage',
  STAFF_VIEW: 'staff:view',
  STAFF_MANAGE: 'staff:manage',
  // Audit
  AUDIT_VIEW: 'audit:view',
  // Documents
  DOCUMENT_VIEW: 'document:view',
  DOCUMENT_MANAGE: 'document:manage',
  // Notifications
  NOTIFICATION_VIEW: 'notification:view',
  NOTIFICATION_MANAGE: 'notification:manage',
  // Admission / IPD
  ADMISSION_VIEW: 'admission:view',
  ADMISSION_CREATE: 'admission:create',
  ADMISSION_DISCHARGE: 'admission:discharge',
  ADMISSION_TRANSFER: 'admission:transfer',
  // ER
  ER_VIEW: 'er:view',
  ER_REGISTER: 'er:register',
  ER_DOCUMENT: 'er:document',
  ER_DISPOSITION: 'er:disposition',
  ER_MANAGE: 'er:manage',
  TRIAGE_ASSIGN: 'triage:assign',
  // ICU
  ICU_ADMIT: 'icu:admit',
  ICU_OBSERVE: 'icu:observe',
  ICU_DOCUMENT: 'icu:document',
  ICU_TRANSFER: 'icu:transfer',
  // OT
  OT_SCHEDULE: 'ot:schedule',
  OT_DOCUMENT: 'ot:document',
  OT_CHECKLIST: 'ot:checklist',
  OT_CLOSE: 'ot:close',
  // Blood Bank
  BLOODBANK_REGISTER_DONOR: 'bloodbank:register_donor',
  BLOODBANK_PROCESS: 'bloodbank:process',
  BLOODBANK_ISSUE: 'bloodbank:issue',
  BLOODBANK_TRANSFUSE: 'bloodbank:transfuse',
  BLOODBANK_DISCARD: 'bloodbank:discard',
  // Nursing
  NURSING_DOCUMENT: 'nursing:document',
  MAR_ADMINISTER: 'mar:administer',
  // HR
  HR_EMPLOYEE: 'hr:employee',
  HR_ROSTER: 'hr:roster',
  HR_ATTENDANCE: 'hr:attendance',
  HR_LEAVE: 'hr:leave',
  // Organization / Facility
  ORGANIZATION_VIEW: 'organization:view',
  ORGANIZATION_MANAGE: 'organization:manage',
  FACILITY_VIEW: 'facility:view',
  FACILITY_CREATE: 'facility:create',
  BRANCH_VIEW: 'branch:view',
  BRANCH_MANAGE: 'branch:manage',
  // Portal
  PORTAL_MANAGE: 'portal:manage',
  // Integration
  INTEGRATION_VIEW: 'integration:view',
  INTEGRATION_MANAGE: 'integration:manage',
  // Queue
  QUEUE_VIEW: 'queue:view',
  // Lab specimen
  LAB_SPECIMEN: 'lab:specimen',
  // Follow-up
  FOLLOWUP_VIEW: 'followup:view',
  FOLLOWUP_MANAGE: 'followup:manage',
  // Forms
  FORMS_VIEW: 'forms:view',
  FORMS_CREATE: 'forms:create',
  FORMS_MANAGE: 'forms:manage',
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
  [ROLES.LAB_SUPERVISOR]: 'laboratory',
  [ROLES.RADIOGRAPHER]: 'radiology',
  [ROLES.RADIOLOGIST]: 'radiology',
};

// ── Access hook ──
export function useAccess() {
  const { user, assignments } = useAuth();
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

    // Role → permission mapping (EXACT mirror of backend RolePermissionSeeder)
    // This is used for UI visibility only — backend authorize: middleware is authoritative.
    const rolePermissions: Record<string, PermissionCode[]> = {
      [ROLES.SUPERADMIN]: [
        // Superadmin has ALL permissions in platform context.
        // In tenant context (support session), uses support_agent permissions.
        PERMISSIONS.USER_VIEW, PERMISSIONS.USER_CREATE,
        PERMISSIONS.ROLE_VIEW, PERMISSIONS.ROLE_ASSIGN, PERMISSIONS.ROLE_REVOKE,
        PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.ORGANIZATION_VIEW, PERMISSIONS.ORGANIZATION_MANAGE,
      ],
      [ROLES.ORG_ADMIN]: [
        PERMISSIONS.ORGANIZATION_VIEW,
        PERMISSIONS.FACILITY_VIEW, PERMISSIONS.FACILITY_CREATE,
        PERMISSIONS.USER_VIEW, PERMISSIONS.USER_CREATE,
        PERMISSIONS.ROLE_VIEW, PERMISSIONS.ROLE_ASSIGN, PERMISSIONS.ROLE_REVOKE,
        PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.BRANCH_VIEW, PERMISSIONS.BRANCH_MANAGE,
        PERMISSIONS.DEPARTMENT_VIEW, PERMISSIONS.DEPARTMENT_MANAGE,
        PERMISSIONS.STAFF_VIEW, PERMISSIONS.STAFF_MANAGE,
        PERMISSIONS.SETTINGS_VIEW, PERMISSIONS.SETTINGS_MANAGE,
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_REGISTER, PERMISSIONS.PATIENT_UPDATE, PERMISSIONS.PATIENT_SEARCH, PERMISSIONS.PATIENT_MERGE,
        PERMISSIONS.INSURANCE_VIEW, PERMISSIONS.INSURANCE_MANAGE,
        PERMISSIONS.DOCUMENT_VIEW, PERMISSIONS.DOCUMENT_MANAGE,
        PERMISSIONS.PAYER_VIEW, PERMISSIONS.PAYER_MANAGE,
        PERMISSIONS.APPOINTMENT_VIEW, PERMISSIONS.APPOINTMENT_BOOK, PERMISSIONS.APPOINTMENT_CHECKIN, PERMISSIONS.APPOINTMENT_CANCEL,
        PERMISSIONS.QUEUE_VIEW,
        PERMISSIONS.ENCOUNTER_VIEW, PERMISSIONS.ENCOUNTER_CREATE, PERMISSIONS.ENCOUNTER_DOCUMENT, PERMISSIONS.ENCOUNTER_PRESCRIBE, PERMISSIONS.ENCOUNTER_SIGN,
        PERMISSIONS.MEDICATION_VIEW, PERMISSIONS.MEDICATION_MANAGE,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_ORDER, PERMISSIONS.LAB_MANAGE, PERMISSIONS.LAB_ACKNOWLEDGE, PERMISSIONS.LAB_ESCALATE, PERMISSIONS.LAB_CORRECT,
        PERMISSIONS.PHARMACY_VIEW, PERMISSIONS.PHARMACY_STOCK,
        PERMISSIONS.BILLING_VIEW, PERMISSIONS.BILLING_INVOICE, PERMISSIONS.BILLING_COLLECT, PERMISSIONS.BILLING_REFUND, PERMISSIONS.BILLING_REFUND_APPROVE, PERMISSIONS.BILLING_RECONCILE, PERMISSIONS.BILLING_VOID,
        PERMISSIONS.INVENTORY_TRANSFER, PERMISSIONS.INVENTORY_ADJUST_REQUEST, PERMISSIONS.INVENTORY_ADJUST_APPROVE,
        PERMISSIONS.PROCUREMENT_VIEW, PERMISSIONS.PROCUREMENT_REQUEST, PERMISSIONS.PROCUREMENT_APPROVE, PERMISSIONS.PROCUREMENT_ORDER, PERMISSIONS.PROCUREMENT_RECEIVE, PERMISSIONS.PROCUREMENT_CONTRACT,
        PERMISSIONS.INSURANCE_CLAIM, PERMISSIONS.INSURANCE_SETTLE,
        PERMISSIONS.ADMISSION_VIEW, PERMISSIONS.ADMISSION_CREATE, PERMISSIONS.ADMISSION_DISCHARGE, PERMISSIONS.ADMISSION_TRANSFER,
        PERMISSIONS.NURSING_DOCUMENT, PERMISSIONS.MAR_ADMINISTER,
        PERMISSIONS.ER_VIEW, PERMISSIONS.ER_REGISTER, PERMISSIONS.TRIAGE_ASSIGN, PERMISSIONS.ER_DOCUMENT, PERMISSIONS.ER_DISPOSITION, PERMISSIONS.ER_MANAGE,
        PERMISSIONS.RADIOLOGY_VIEW, PERMISSIONS.RADIOLOGY_ORDER, PERMISSIONS.RADIOLOGY_SCHEDULE, PERMISSIONS.RADIOLOGY_PERFORM, PERMISSIONS.RADIOLOGY_REPORT, PERMISSIONS.RADIOLOGY_VERIFY, PERMISSIONS.RADIOLOGY_MANAGE,
        PERMISSIONS.HR_EMPLOYEE, PERMISSIONS.HR_ROSTER, PERMISSIONS.HR_ATTENDANCE, PERMISSIONS.HR_LEAVE,
        PERMISSIONS.OT_SCHEDULE, PERMISSIONS.OT_DOCUMENT, PERMISSIONS.OT_CHECKLIST, PERMISSIONS.OT_CLOSE,
        PERMISSIONS.ICU_ADMIT, PERMISSIONS.ICU_OBSERVE, PERMISSIONS.ICU_DOCUMENT, PERMISSIONS.ICU_TRANSFER,
        PERMISSIONS.BLOODBANK_REGISTER_DONOR, PERMISSIONS.BLOODBANK_PROCESS, PERMISSIONS.BLOODBANK_ISSUE, PERMISSIONS.BLOODBANK_TRANSFUSE, PERMISSIONS.BLOODBANK_DISCARD,
        PERMISSIONS.ANALYTICS_VIEW, PERMISSIONS.ANALYTICS_MANAGE, PERMISSIONS.REPORTS_RUN, PERMISSIONS.REPORTS_SCHEDULE, PERMISSIONS.REPORTS_EXPORT,
        PERMISSIONS.PORTAL_MANAGE,
        PERMISSIONS.INTEGRATION_VIEW, PERMISSIONS.INTEGRATION_MANAGE,
        PERMISSIONS.FORMS_VIEW, PERMISSIONS.FORMS_CREATE, PERMISSIONS.FORMS_MANAGE,
        PERMISSIONS.BRANDING_VIEW, PERMISSIONS.BRANDING_MANAGE,
        PERMISSIONS.NOTIFICATION_VIEW, PERMISSIONS.NOTIFICATION_MANAGE,
      ],
      [ROLES.HOSPITAL_ADMIN]: [
        PERMISSIONS.FACILITY_VIEW,
        PERMISSIONS.USER_VIEW, PERMISSIONS.ROLE_VIEW, PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.BRANCH_VIEW, PERMISSIONS.BRANCH_MANAGE,
        PERMISSIONS.DEPARTMENT_VIEW, PERMISSIONS.DEPARTMENT_MANAGE,
        PERMISSIONS.STAFF_VIEW, PERMISSIONS.STAFF_MANAGE,
        PERMISSIONS.SETTINGS_VIEW, PERMISSIONS.SETTINGS_MANAGE,
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_REGISTER, PERMISSIONS.PATIENT_UPDATE, PERMISSIONS.PATIENT_SEARCH, PERMISSIONS.PATIENT_MERGE,
        PERMISSIONS.INSURANCE_VIEW, PERMISSIONS.INSURANCE_MANAGE,
        PERMISSIONS.DOCUMENT_VIEW, PERMISSIONS.DOCUMENT_MANAGE,
        PERMISSIONS.PAYER_VIEW, PERMISSIONS.PAYER_MANAGE,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_MANAGE, PERMISSIONS.LAB_ACKNOWLEDGE, PERMISSIONS.LAB_ESCALATE, PERMISSIONS.LAB_CORRECT,
        PERMISSIONS.APPOINTMENT_VIEW, PERMISSIONS.APPOINTMENT_BOOK, PERMISSIONS.APPOINTMENT_CHECKIN, PERMISSIONS.APPOINTMENT_CANCEL,
        PERMISSIONS.QUEUE_VIEW,
        PERMISSIONS.ENCOUNTER_VIEW, PERMISSIONS.ENCOUNTER_CREATE, PERMISSIONS.ENCOUNTER_DOCUMENT, PERMISSIONS.ENCOUNTER_PRESCRIBE, PERMISSIONS.ENCOUNTER_SIGN,
        PERMISSIONS.MEDICATION_VIEW, PERMISSIONS.MEDICATION_MANAGE,
        PERMISSIONS.PHARMACY_VIEW, PERMISSIONS.PHARMACY_STOCK,
        PERMISSIONS.BILLING_VIEW, PERMISSIONS.BILLING_INVOICE, PERMISSIONS.BILLING_COLLECT, PERMISSIONS.BILLING_REFUND, PERMISSIONS.BILLING_REFUND_APPROVE, PERMISSIONS.BILLING_RECONCILE, PERMISSIONS.BILLING_VOID,
        PERMISSIONS.INVENTORY_TRANSFER, PERMISSIONS.INVENTORY_ADJUST_REQUEST, PERMISSIONS.INVENTORY_ADJUST_APPROVE,
        PERMISSIONS.PROCUREMENT_VIEW, PERMISSIONS.PROCUREMENT_REQUEST, PERMISSIONS.PROCUREMENT_APPROVE, PERMISSIONS.PROCUREMENT_ORDER, PERMISSIONS.PROCUREMENT_RECEIVE, PERMISSIONS.PROCUREMENT_CONTRACT,
        PERMISSIONS.INSURANCE_CLAIM, PERMISSIONS.INSURANCE_SETTLE,
        PERMISSIONS.ADMISSION_VIEW, PERMISSIONS.ADMISSION_CREATE, PERMISSIONS.ADMISSION_DISCHARGE, PERMISSIONS.ADMISSION_TRANSFER,
        PERMISSIONS.NURSING_DOCUMENT, PERMISSIONS.MAR_ADMINISTER,
        PERMISSIONS.ER_VIEW, PERMISSIONS.ER_REGISTER, PERMISSIONS.TRIAGE_ASSIGN, PERMISSIONS.ER_DOCUMENT, PERMISSIONS.ER_DISPOSITION, PERMISSIONS.ER_MANAGE,
        PERMISSIONS.RADIOLOGY_VIEW, PERMISSIONS.RADIOLOGY_ORDER, PERMISSIONS.RADIOLOGY_SCHEDULE, PERMISSIONS.RADIOLOGY_PERFORM, PERMISSIONS.RADIOLOGY_REPORT, PERMISSIONS.RADIOLOGY_VERIFY, PERMISSIONS.RADIOLOGY_MANAGE,
        PERMISSIONS.HR_EMPLOYEE, PERMISSIONS.HR_ROSTER, PERMISSIONS.HR_ATTENDANCE, PERMISSIONS.HR_LEAVE,
        PERMISSIONS.OT_SCHEDULE, PERMISSIONS.OT_DOCUMENT, PERMISSIONS.OT_CHECKLIST, PERMISSIONS.OT_CLOSE,
        PERMISSIONS.ICU_ADMIT, PERMISSIONS.ICU_OBSERVE, PERMISSIONS.ICU_DOCUMENT, PERMISSIONS.ICU_TRANSFER,
        PERMISSIONS.BLOODBANK_REGISTER_DONOR, PERMISSIONS.BLOODBANK_PROCESS, PERMISSIONS.BLOODBANK_ISSUE, PERMISSIONS.BLOODBANK_TRANSFUSE, PERMISSIONS.BLOODBANK_DISCARD,
        PERMISSIONS.ANALYTICS_VIEW, PERMISSIONS.ANALYTICS_MANAGE, PERMISSIONS.REPORTS_RUN, PERMISSIONS.REPORTS_SCHEDULE, PERMISSIONS.REPORTS_EXPORT,
        PERMISSIONS.PORTAL_MANAGE,
        PERMISSIONS.INTEGRATION_VIEW, PERMISSIONS.INTEGRATION_MANAGE,
        PERMISSIONS.FORMS_VIEW, PERMISSIONS.FORMS_CREATE, PERMISSIONS.FORMS_MANAGE,
        PERMISSIONS.BRANDING_VIEW, PERMISSIONS.BRANDING_MANAGE,
        PERMISSIONS.NOTIFICATION_VIEW, PERMISSIONS.NOTIFICATION_MANAGE,
      ],
      [ROLES.DOCTOR]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.APPOINTMENT_VIEW,
        PERMISSIONS.ENCOUNTER_VIEW, PERMISSIONS.ENCOUNTER_CREATE, PERMISSIONS.ENCOUNTER_DOCUMENT, PERMISSIONS.ENCOUNTER_PRESCRIBE, PERMISSIONS.ENCOUNTER_SIGN,
        PERMISSIONS.MEDICATION_VIEW,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_ORDER,
        PERMISSIONS.RADIOLOGY_VIEW, PERMISSIONS.RADIOLOGY_ORDER,
        PERMISSIONS.PHARMACY_VIEW,
        PERMISSIONS.ADMISSION_VIEW,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.NURSE]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.APPOINTMENT_VIEW,
        PERMISSIONS.ENCOUNTER_VIEW,
        PERMISSIONS.MEDICATION_VIEW,
        PERMISSIONS.LAB_VIEW,
        PERMISSIONS.PHARMACY_VIEW,
        PERMISSIONS.ADMISSION_VIEW,
        PERMISSIONS.NURSING_DOCUMENT, PERMISSIONS.MAR_ADMINISTER,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.PHARMACIST]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.MEDICATION_VIEW, PERMISSIONS.MEDICATION_MANAGE,
        PERMISSIONS.PHARMACY_VIEW, PERMISSIONS.PHARMACY_STOCK, PERMISSIONS.PHARMACY_DISPENSE,
        PERMISSIONS.INVENTORY_TRANSFER,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.LAB_TECHNICIAN]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_RESULT_ENTRY, PERMISSIONS.LAB_SPECIMEN,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.LAB_SUPERVISOR]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.LAB_VIEW, PERMISSIONS.LAB_RESULT_ENTRY, PERMISSIONS.LAB_VERIFY,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.RADIOGRAPHER]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.RADIOLOGY_VIEW, PERMISSIONS.RADIOLOGY_PERFORM,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.RADIOLOGIST]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.RADIOLOGY_VIEW, PERMISSIONS.RADIOLOGY_REPORT, PERMISSIONS.RADIOLOGY_VERIFY,
        PERMISSIONS.LAB_VIEW,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.BILLING_CLERK]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.BILLING_VIEW, PERMISSIONS.BILLING_INVOICE, PERMISSIONS.BILLING_COLLECT,
        PERMISSIONS.ANALYTICS_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.RECEPTIONIST]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_REGISTER, PERMISSIONS.PATIENT_UPDATE, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.APPOINTMENT_VIEW, PERMISSIONS.APPOINTMENT_BOOK, PERMISSIONS.APPOINTMENT_CHECKIN,
        PERMISSIONS.QUEUE_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.ORG_FINANCE]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.BILLING_VIEW, PERMISSIONS.BILLING_RECONCILE, PERMISSIONS.BILLING_VOID,
        PERMISSIONS.PROCUREMENT_VIEW, PERMISSIONS.PROCUREMENT_APPROVE,
        PERMISSIONS.INSURANCE_CLAIM, PERMISSIONS.INSURANCE_SETTLE,
        PERMISSIONS.ANALYTICS_VIEW, PERMISSIONS.REPORTS_RUN,
        PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
      [ROLES.BRANCH_MANAGER]: [
        PERMISSIONS.PATIENT_VIEW, PERMISSIONS.PATIENT_SEARCH,
        PERMISSIONS.APPOINTMENT_VIEW,
        PERMISSIONS.ENCOUNTER_VIEW,
        PERMISSIONS.BILLING_VIEW,
        PERMISSIONS.ANALYTICS_VIEW,
        PERMISSIONS.AUDIT_VIEW,
        PERMISSIONS.DOCUMENT_VIEW,
        PERMISSIONS.NOTIFICATION_VIEW,
      ],
    };

    // Check all assigned roles (assignments come from useAuth(), not user object)
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
    for (const assignment of assignments) {
      const roles: string[] = assignment.roles ?? [];
      for (const role of roles) {
        if (ROLE_DEFAULT_MODULE[role]) return ROLE_DEFAULT_MODULE[role];
      }
    }
    return 'hospital';
  };

  /**
   * Get the user's professional display name.
   * Priority: staff profile name > role-based label > email prefix.
   * Never expose internal fixture/seed identifiers (smoke.*, test.*) to the UI.
   */
  const getDisplayName = (): string => {
    // If a staff profile full name exists, use it
    const staffName = (user as any)?.staffName;
    if (staffName && typeof staffName === 'string' && staffName.trim()) {
      return staffName.trim();
    }
    // Map role codes to professional labels
    const roleLabels: Record<string, string> = {
      superadmin: 'Super Admin',
      org_admin: 'Organization Admin',
      hospital_admin: 'Hospital Admin',
      doctor: 'Doctor',
      nurse: 'Nurse',
      pharmacist: 'Pharmacist',
      lab_technician: 'Lab Technician',
      lab_supervisor: 'Lab Supervisor',
      radiographer: 'Radiographer',
      radiologist: 'Radiologist',
      billing_clerk: 'Billing Clerk',
      receptionist: 'Receptionist',
      org_finance: 'Finance',
      branch_manager: 'Branch Manager',
      support_agent: 'Support',
    };
    // Use the first assigned role's professional label (assignments from useAuth())
    for (const assignment of assignments) {
      const roles: string[] = assignment.roles ?? [];
      for (const role of roles) {
        if (roleLabels[role]) return roleLabels[role];
      }
    }
    // Fallback: sanitize email prefix (remove fixture prefixes like smoke.)
    const email = user?.email ?? '';
    const prefix = email.split('@')[0] ?? 'User';
    // Remove common fixture/test prefixes
    const sanitized = prefix.replace(/^smoke\./, '').replace(/^test\./, '').replace(/^demo\./, '');
    return sanitized.charAt(0).toUpperCase() + sanitized.slice(1) || 'User';
  };

  /**
   * Get the user's initials for avatar.
   */
  const getInitials = (): string => {
    const name = getDisplayName();
    // If the name is a role label (e.g. "Super Admin"), use first letters of each word
    const words = name.split(' ');
    if (words.length >= 2) {
      return (words[0][0] + words[1][0]).toUpperCase();
    }
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
