/**
 * The `organizations:schedule-exceptions` domain function (pure request
 * handler, Phase 44) — the organization-scoped schedule-exception read,
 * mirroring the established Laravel contract exactly
 * (ScheduleController::exceptions — the
 * `organizations/{organization}/schedule-exceptions` route, the direct
 * sibling of the Phase 43 schedule-templates read).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'schedule:view')`
 *     capability (the SAME gate as the Phase 43 templates read — the
 *     Laravel route gate `authorize:schedule:view`. The
 *     RolePermissionSeeder grants it to support_agent / org_admin /
 *     hospital_admin / branch_manager / receptionist / doctor / nurse —
 *     the seeded billing_clerk role does NOT hold it). A principal with
 *     the related view permissions alone is DENIED — the gate is
 *     `schedule:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility scope comes exclusively from
 *     the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33–43 reads): an
 *     organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the exceptions are read under the claims (schedule_exceptions
 *     is **TENANT_FACILITY** — NOT TENANT_FACILITY_BRANCH:
 *     schedule_exceptions has NO branch_id column, so the select policy
 *     is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS
 *     NULL)` — there is NO branch clause), bound to the verified
 *     organization id, eager-loaded with the staff reference (the exact
 *     `->with('staff:id,full_name')` — NOTE: unlike the templates read,
 *     `presentException()` does NOT expose the staff reference; the eager
 *     load is a query-level detail only), and ordered by `exception_date`
 *     DESCENDING — the exact `->orderByDesc('exception_date')`. The
 *     facility filter is applied ONLY when the caller has a facility
 *     scope (the exact `! $context->isPlatform && $context->facilityId()
 *     !== null` guard — org-level / platform callers see every facility
 *     of the tenant). A branch proposal is IRRELEVANT to this read —
 *     schedule_exceptions has no branch dimension. **Schedule exceptions
 *     are NOT soft-deletable** — the ScheduleException model has NO
 *     SoftDeletes trait and the table has NO `deleted_at` column
 *     ("date-scoped rows expire naturally — no soft delete"); there is no
 *     soft-delete filter to reproduce;
 *  6. present ONLY the approved exception fields — the exact
 *     ScheduleController::presentException map: {id, facilityId, staffId,
 *     exceptionDate, reason, status}. The controller performs NO partial
 *     select — the full model hydrates — so `facilityId` carries the
 *     real value (NOT NULL in the base schema) and `staffId` is NOT NULL.
 *     `exceptionDate` is the date cast's `toDateString()` (`YYYY-MM-DD`);
 *     `reason` ∈ leave/holiday/block (the CHECK constraint) — the reason
 *     is NOT NULL text; `status` ∈ active/cancelled (the CHECK
 *     constraint — the store flow writes 'active', a cancellation
 *     transitions it to 'cancelled'). NO status filter — active AND
 *     cancelled both presented. NO other related data (the `template_id`
 *     column exists but is never presented; the eager staff ref is never
 *     presented), NO actor/correlation fields, NO tenant/timestamp/audit
 *     metadata;
 *  7. NO audit — ScheduleController::exceptions records no audit event
 *     (`schedule.exception.created` is a write-side event only);
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No pagination (bare `->get()` array). No invented fields.
 * No RLS weakening. No SECURITY DEFINER. No service-role credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { organizationIdFromUrl } from './organizations_departments.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented schedule exception (the exact ScheduleController::presentException map). */
export interface ScheduleExceptionRow {
  id: string;
  facilityId: string;
  staffId: string;
  exceptionDate: string;
  reason: string;
  status: string;
}

/** The NOT-FOUND classes a schedule-exception read can produce (AccessCheck::organization). */
export type OrganizationScheduleExceptionsResult = ScheduleExceptionRow[] | 'organization-not-found' | null;

export interface OrganizationsScheduleExceptionsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped schedule-exception read (swasthya_app under the claims;
   * the organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's schedule exceptions (ordered by
   * `exception_date` DESC — the exact `->orderByDesc('exception_date')`,
   * with the eager staff load `with('staff:id,full_name')` — a query-level
   * detail; the staff reference is NOT presented; the facility filter
   * applied ONLY when the caller has a facility claim; schedule_exceptions
   * is **TENANT_FACILITY** — the select policy is `tenant_id = TENANT AND
   * (facility_id = FACILITY OR FACILITY IS NULL)` — NO branch clause (no
   * branch_id column, so a branch proposal does not narrow); NOT
   * soft-deletable — no SoftDeletes trait, no `deleted_at` column; NO
   * status filter — active AND cancelled both return; `exceptionDate` as
   * `YYYY-MM-DD`, `reason` ∈ leave/holiday/block, `status` ∈
   * active/cancelled). No mutation.
   */
  listOrganizationScheduleExceptions: (claims: Claims, organizationId: string) => OrganizationScheduleExceptionsResult;
}

export async function handleOrganizationsScheduleExceptions(req: Request, deps: OrganizationsScheduleExceptionsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:schedule:view` — the SAME
  // capability as the Phase 43 schedule-templates read.
  if (!can(context, 'schedule:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const organizationId = organizationIdFromUrl(req);

  // A missing identifier can never resolve — 404 'Resource not found.'
  // (the established malformed/nonexistent convention; never 400/422).
  // Like the Phase 33–43 organization selectors there is NO UUID gate:
  // AccessCheck::organization resolves with `find($id)` and the route
  // binding is the Laravel 404 source for unknown ids. An unknown/
  // malformed id resolves to null at the dependency → the AccessCheck-layer
  // 404 'Organization not found.' below; a KNOWN organization outside the
  // caller's scope → the deny(read) 404 'Resource not found.'.
  if (organizationId === '') {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS + AccessCheck decide visibility. The id is a resource selector —
  // never authorization scope.
  const exceptions = deps.listOrganizationScheduleExceptions(claims, organizationId);

  if (exceptions === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (exceptions === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact ScheduleController::exceptions data shape: the bare exception
  // list (already ordered by exception_date DESC by the RLS-scoped read),
  // wrapped in the standard envelope. No audit — the Laravel read does not
  // audit.
  return successEnvelope(
    exceptions,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
