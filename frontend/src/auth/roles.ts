/**
 * Client-side role gating — UX only. The backend `authorize:` gates and RLS
 * remain authoritative (SECURITY.md §27, TENANCY.md V2 §8); hiding a screen
 * here never grants anything, and showing it never bypasses a backend check.
 *
 * These lists are the client mirror of the seeded RBAC catalog
 * (backend/database/seeders/RolePermissionSeeder.php). Invariants:
 *
 *   1. Every role code listed here MUST exist in that catalog — a nav item or
 *      page gate that references a code the backend never issues is inert.
 *   2. A screen's nav item and its page gate MUST use the same list, or a
 *      principal sees a destination the page then denies.
 *
 * Keep this file in lockstep with the seeder: add a role here only when the
 * catalog defines it and the underlying permission is actually granted.
 */
export const QUEUE_ROLES = ['hospital_admin', 'doctor', 'nurse', 'receptionist'] as const;
export const BILLING_ROLES = ['hospital_admin', 'org_admin', 'billing_clerk'] as const;
export const AUDIT_ROLES = ['hospital_admin', 'org_admin', 'org_finance', 'branch_manager', 'superadmin'] as const;

/** Admin module gates — UX-only (backend is authoritative). */
export const ADMIN_ROLES = ['superadmin', 'org_admin', 'hospital_admin'] as const;
export const STAFF_MANAGE_ROLES = ['superadmin', 'org_admin', 'hospital_admin'] as const;
export const DEPARTMENT_MANAGE_ROLES = ['superadmin', 'org_admin', 'hospital_admin'] as const;
export const SERVICE_MANAGE_ROLES = ['superadmin', 'org_admin', 'hospital_admin'] as const;
export const SETTINGS_MANAGE_ROLES = ['superadmin', 'org_admin', 'hospital_admin'] as const;
