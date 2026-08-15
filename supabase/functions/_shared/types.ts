/**
 * Shared domain shapes for Swasthya Edge Functions.
 *
 * These mirror the authoritative backend models (App\Models\*). Only the
 * fields the edge layer needs are declared; the DB wiring maps the actual
 * rows onto these shapes. NO field here is ever trusted from the client —
 * everything is loaded server-side from the authenticated identity.
 */

/** The application user account (backend users table). */
export interface AppUser {
  id: string;
  email?: string;
  status: 'pending' | 'active' | 'locked' | 'disabled';
}

/** Permission carried by a role: code + authorization scope. */
export interface Permission {
  code: string;
  scope: 'tenant' | 'platform' | 'both';
}

/** A role as loaded by the edge layer. */
export interface Role {
  id: string;
  code: string;
  scopeType: 'platform' | 'organization' | 'facility';
  permissions: Permission[];
}

/** An ACTIVE role assignment (backend role_assignments). */
export interface Assignment {
  id: string;
  userId: string;
  roleId: string;
  role: Role | null;
  tenantId: string | null;
  facilityId: string | null;
  branchId: string | null;
  scopeType: string;
}

/** Organization facts needed for context resolution (backend organizations). */
export interface OrganizationInfo {
  id: string;
  status: 'active' | string;
  timezone?: string;
}

/** Facility facts needed for context resolution (backend facilities). */
export interface FacilityInfo {
  id: string;
  tenantId: string;
  timezone?: string;
}

/** Branch facts needed for branch proposal validation (backend branches). */
export interface BranchInfo {
  id: string;
  tenantId: string;
  facilityId: string;
}

/** An active support session (backend support_sessions). */
export interface SupportSessionInfo {
  id: string;
  organizationId: string;
  facilityId: string | null;
}

export type ContextKind = 'platform' | 'support' | 'tenant';

/**
 * The immutable, server-derived context every edge operation executes inside
 * — the Edge Function mirror of backend App\Support\TenantContext.
 */
export interface ResolvedContext {
  kind: ContextKind;
  isPlatform: boolean;
  user: AppUser | null;
  organizationId: string | null;
  facilityId: string | null;
  branchId: string | null;
  assignments: Assignment[];
  supportSessionId: string | null;
}
