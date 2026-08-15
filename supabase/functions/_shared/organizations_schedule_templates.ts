/**
 * The `organizations:schedule-templates` domain function (pure request
 * handler, Phase 43) — the organization-scoped schedule-template read,
 * mirroring the established Laravel contract exactly
 * (ScheduleController::templates — the
 * `organizations/{organization}/schedule-templates` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'schedule:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:schedule:view` — a DISTINCT capability from the
 *     patient/insurance/consent/document/department/branch/location/ward/
 *     room/bed/staff/service/payer/medication reads. The
 *     RolePermissionSeeder grants it to support_agent / org_admin /
 *     hospital_admin / branch_manager / receptionist / doctor / nurse —
 *     the seeded billing_clerk role does NOT hold it). A principal with
 *     the related view permissions alone is DENIED — the gate is
 *     `schedule:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility scope comes exclusively from
 *     the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33–42 reads): an
 *     organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the templates are read under the claims (schedule_templates is
 *     **TENANT_FACILITY** — NOT TENANT_FACILITY_BRANCH: schedule_templates
 *     has NO branch_id column, so the select policy is `tenant_id = TENANT
 *     AND (facility_id = FACILITY OR FACILITY IS NULL)` — there is NO
 *     branch clause), bound to the verified organization id, eager-loaded
 *     with the staff reference (the exact `->with('staff:id,full_name,
 *     designation')`), and ordered by `day_of_week` ascending — the exact
 *     `->orderBy('day_of_week')`. The facility filter is applied ONLY when
 *     the caller has a facility scope (the exact `! $context->isPlatform
 *     && $context->facilityId() !== null` guard — org-level / platform
 *     callers see every facility of the tenant). A branch proposal is
 *     IRRELEVANT to this read — schedule_templates has no branch
 *     dimension. **Schedule templates ARE soft-deletable** — the Laravel
 *     model uses SoftDeletes, so the read excludes soft-deleted rows
 *     (`deleted_at is null`) exactly as the model's default scope does;
 *     (staff itself has NO SoftDeletes and the composite FK is RESTRICT,
 *     so the eager staff ref always resolves in a consistent DB — the
 *     `?: null` in presentTemplate is unreachable in practice);
 *  6. present ONLY the approved template fields — the exact
 *     ScheduleController::presentTemplate map: {id, facilityId, staffId,
 *     staff, serviceId, dayOfWeek, startsAt, endsAt, slotMinutes,
 *     capacity, validFrom, validTo, status}. The controller performs NO
 *     partial select — the full model hydrates — so `facilityId` carries
 *     the real value (NOT NULL in the base schema) and `staffId` is NOT
 *     NULL with the eager ref {id, fullName, designation} (null only if
 *     the composite-FK relation cannot resolve — e.g. a soft-deleted
 *     staff row); `serviceId` is NULLABLE (the composite FK allows NULL —
 *     a template may be service-less); `startsAt`/`endsAt` are the TIME
 *     columns formatted `H:i` (the datetime cast's format — e.g. '09:00');
 *     `slotMinutes`/`capacity` are integers (slot 5–240, capacity >= 1);
 *     `validFrom`/`validTo` are the date casts' `toDateString()`
 *     (`YYYY-MM-DD`; validTo nullable); `dayOfWeek` ∈ 0..6 (ISO 8601 —
 *     0 Sun .. 6 Sat); `status` ∈ active/inactive. NO status filter —
 *     active AND inactive both presented. NO other related data, NO
 *     actor/correlation fields, NO tenant/timestamp/audit metadata;
 *  7. NO audit — ScheduleController::templates records no audit event
 *     (`schedule.template.created` is a write-side event only);
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

/** The eager staff reference (the exact `with('staff:id,full_name,designation')` ref). */
export interface ScheduleTemplateStaffRef {
  id: string;
  fullName: string;
  designation: string;
}

/** One presented schedule template (the exact ScheduleController::presentTemplate map). */
export interface ScheduleTemplateRow {
  id: string;
  facilityId: string;
  staffId: string;
  staff: ScheduleTemplateStaffRef | null;
  serviceId: string | null;
  dayOfWeek: number;
  startsAt: string;
  endsAt: string;
  slotMinutes: number;
  capacity: number;
  validFrom: string;
  validTo: string | null;
  status: string;
}

/** The NOT-FOUND classes a schedule-template read can produce (AccessCheck::organization). */
export type OrganizationScheduleTemplatesResult = ScheduleTemplateRow[] | 'organization-not-found' | null;

export interface OrganizationsScheduleTemplatesDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped schedule-template read (swasthya_app under the claims;
   * the organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's schedule templates (ordered by `day_of_week`
   * ASC — the exact `->orderBy('day_of_week')`, with the eager staff ref
   * `with('staff:id,full_name,designation')`; the facility filter applied
   * ONLY when the caller has a facility claim; schedule_templates is
   * **TENANT_FACILITY** — the select policy is `tenant_id = TENANT AND
   * (facility_id = FACILITY OR FACILITY IS NULL)` — NO branch clause (no
   * branch_id column, so a branch proposal does not narrow);
   * **schedule templates ARE soft-deletable** — the Laravel SoftDeletes
   * model scope excludes `deleted_at is not null` rows, reproduced
   * exactly; NO status filter — active AND inactive both return;
   * `serviceId` nullable, `staffId` NOT NULL with the eager ref (staff has
   * NO SoftDeletes — the ref always resolves in a consistent DB),
   * `validTo` nullable, times as `H:i`). No mutation.
   */
  listOrganizationScheduleTemplates: (claims: Claims, organizationId: string) => OrganizationScheduleTemplatesResult;
}

export async function handleOrganizationsScheduleTemplates(req: Request, deps: OrganizationsScheduleTemplatesDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:schedule:view` — a
  // DISTINCT capability from the patient/insurance/consent/document/
  // department/branch/location/ward/room/bed/staff/service/payer/medication
  // reads.
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
  // Like the Phase 33–42 organization selectors there is NO UUID gate:
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
  const templates = deps.listOrganizationScheduleTemplates(claims, organizationId);

  if (templates === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (templates === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact ScheduleController::templates data shape: the bare template
  // list (already ordered by day_of_week ASC by the RLS-scoped read),
  // wrapped in the standard envelope. No audit — the Laravel read does not
  // audit.
  return successEnvelope(
    templates,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
