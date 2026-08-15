/**
 * Server-side context resolution for Edge Functions (Edge mirror of
 * backend App\Http\Middleware\ResolveTenantContext).
 *
 * The context is DERIVED from the authenticated principal's ACTIVE role
 * assignments — never from the client:
 *
 *  1. platform assignment, no active support session → platform context
 *     (empty tenant/facility/branch; the database refuses tenant rows);
 *  2. platform assignment WITH active support session → support context
 *     (the session's organization/facility, read-only support_agent role);
 *  3. no active assignments → default deny (403 FORBIDDEN);
 *  4. X-Swasthya-Facility is a PROPOSAL: must match an active assignment,
 *     the tenant derives from that assignment (403 FACILITY_DENIED);
 *  5. X-Swasthya-Branch is likewise a PROPOSAL, valid only against the
 *     resolved facility (403 BRANCH_DENIED);
 *  6. a non-active organization → 403 TENANT_SUSPENDED.
 *
 * This module is pure: all data enters through the `input` object (loaded
 * server-side by the caller) — there is no I/O, so it is fully executable in
 * the local harness and inside Deno alike.
 */
import { EdgeError, ErrorCodes } from './errors.ts';
import type {
  Assignment,
  AppUser,
  BranchInfo,
  FacilityInfo,
  OrganizationInfo,
  ResolvedContext,
  SupportSessionInfo,
} from './types.ts';

export interface ContextDeps {
  loadOrganization(id: string): OrganizationInfo | null;
  loadFacility(id: string): FacilityInfo | null;
  loadBranch(id: string): BranchInfo | null;
}

export interface ContextInput {
  /** The authenticated application user (already resolved from the JWT sub). */
  user: AppUser | null;
  /** The user's ACTIVE role assignments, loaded server-side. */
  assignments: Assignment[];
  /** Platform-administration route (api/v1/platform/* equivalent). */
  isPlatformRoute: boolean;
  /** Client proposals only — validated here, never trusted. */
  proposals: { facilityId?: string | null; branchId?: string | null };
  /** The user's active support session, if any (loaded server-side). */
  activeSupportSession: SupportSessionInfo | null;
  deps: ContextDeps;
}

const SUPPORT_AGENT_PERMISSIONS = [
  { code: 'patient:view', scope: 'tenant' as const },
  { code: 'patient:search', scope: 'tenant' as const },
  { code: 'appointment:view', scope: 'tenant' as const },
  { code: 'encounter:view', scope: 'tenant' as const },
  { code: 'billing:view', scope: 'tenant' as const },
  { code: 'audit:view', scope: 'tenant' as const },
];

export function resolveContext(input: ContextInput): ResolvedContext {
  const { user, assignments } = input;

  if (user === null) {
    throw new EdgeError(ErrorCodes.INVALID_TOKEN, 'Authentication required.', 401);
  }
  if (user.status !== 'active') {
    throw new EdgeError(ErrorCodes.FORBIDDEN, 'This account is not active.', 403);
  }

  const platformAssignment = assignments.find((a) => a.role?.scopeType === 'platform');

  if (platformAssignment !== undefined) {
    if (input.isPlatformRoute || input.activeSupportSession === null) {
      return {
        kind: 'platform',
        isPlatform: true,
        user,
        organizationId: null,
        facilityId: null,
        branchId: null,
        assignments,
        supportSessionId: null,
      };
    }

    // Support context: the session IS the tenant selection.
    const session = input.activeSupportSession;
    const organization = input.deps.loadOrganization(session.organizationId);

    if (organization === null || organization.status !== 'active') {
      throw new EdgeError(
        ErrorCodes.TENANT_SUSPENDED,
        'This organization is not active. Contact your administrator.',
        403,
      );
    }

    const facility = session.facilityId !== null
      ? input.deps.loadFacility(session.facilityId)
      : null;

    const supportAssignment: Assignment = {
      id: `support:${session.id}`,
      userId: user.id,
      roleId: 'support_agent',
      role: {
        id: 'support_agent',
        code: 'support_agent',
        scopeType: 'facility',
        permissions: SUPPORT_AGENT_PERMISSIONS,
      },
      tenantId: session.organizationId,
      facilityId: session.facilityId,
      branchId: null,
      scopeType: 'facility',
    };

    return {
      kind: 'support',
      isPlatform: false,
      user,
      organizationId: session.organizationId,
      facilityId: facility?.id ?? null,
      branchId: null,
      assignments: [supportAssignment],
      supportSessionId: session.id,
    };
  }

  if (assignments.length === 0) {
    throw new EdgeError(
      ErrorCodes.FORBIDDEN,
      'No active role assignments — you have no access to any organization.',
      403,
    );
  }

  let organizationId: string | null;
  let facilityId: string | null;

  const facilityProposal = input.proposals.facilityId;
  if (facilityProposal !== undefined && facilityProposal !== null && facilityProposal !== '') {
    const match = assignments.find(
      (a) => a.facilityId !== null && a.facilityId === facilityProposal,
    );
    if (match === undefined) {
      throw new EdgeError(
        ErrorCodes.FACILITY_DENIED,
        'The requested facility is outside your assigned scope.',
        403,
      );
    }
    organizationId = match.tenantId;
    facilityId = match.facilityId;
  } else {
    const fallback = assignments[0];
    organizationId = fallback.tenantId ?? null;
    facilityId = fallback.facilityId ?? null;
  }

  const organization = organizationId !== null
    ? input.deps.loadOrganization(organizationId)
    : null;

  if (organization === null || organization.status !== 'active') {
    throw new EdgeError(
      ErrorCodes.TENANT_SUSPENDED,
      'This organization is not active. Contact your administrator.',
      403,
    );
  }

  let branchId: string | null = null;
  const branchProposal = input.proposals.branchId;
  if (branchProposal !== undefined && branchProposal !== null && branchProposal !== '') {
    if (facilityId === null) {
      throw new EdgeError(
        ErrorCodes.BRANCH_DENIED,
        'Branch context requires a facility context.',
        403,
      );
    }
    const branch = input.deps.loadBranch(branchProposal);
    if (
      branch === null
      || branch.tenantId !== organizationId
      || branch.facilityId !== facilityId
    ) {
      throw new EdgeError(
        ErrorCodes.BRANCH_DENIED,
        'The requested branch is outside your assigned scope.',
        403,
      );
    }
    branchId = branch.id;
  }

  return {
    kind: 'tenant',
    isPlatform: false,
    user,
    organizationId,
    facilityId,
    branchId,
    assignments,
    supportSessionId: null,
  };
}
