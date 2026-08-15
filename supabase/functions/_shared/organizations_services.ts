/**
 * The `organizations:services` domain function (pure request handler, Phase 40)
 * — the organization-scoped service-catalog read, mirroring the established
 * Laravel contract exactly (ServiceController::index — the
 * `organizations/{organization}/services` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'service:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:service:view` — a DISTINCT capability from the
 *     patient/insurance/consent/document/department/branch/location/ward/
 *     room/bed/staff reads; held by the support_agent / org_admin /
 *     hospital_admin / branch_manager roles in the RolePermissionSeeder —
 *     the seeded receptionist / billing_clerk / doctor / nurse roles do NOT
 *     hold it). A principal with the related view permissions alone is
 *     DENIED — the gate is `service:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility scope comes exclusively from
 *     the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33/35/36/37/38/39
 *     reads): an organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the services are read under the claims (services is
 *     **TENANT_FACILITY** — NOT TENANT_FACILITY_BRANCH: services has NO
 *     branch_id column, so the select policy is `tenant_id = TENANT AND
 *     (facility_id = FACILITY OR FACILITY IS NULL)` — there is NO branch
 *     clause), bound to the verified organization id, eager-loaded with
 *     the department reference (the exact
 *     `->with('department:id,code,name')`), and ordered by `name`
 *     ascending — the exact `->orderBy('name')`. The facility filter is
 *     applied ONLY when the caller has a facility scope (the exact
 *     `! $context->isPlatform && $context->facilityId() !== null` guard —
 *     org-level / platform callers see every facility of the tenant). A
 *     branch proposal is IRRELEVANT to this read — services has no branch
 *     dimension. **Services ARE soft-deletable** (unlike staff/beds) — the
 *     Laravel model uses SoftDeletes, so the read excludes soft-deleted
 *     rows (`deleted_at is null`) exactly as the model's default scope
 *     does;
 *  6. present ONLY the approved service fields — the exact
 *     ServiceController::index map: {id, facilityId, departmentId,
 *     department, name, code, serviceType, status,
 *     defaultDurationMinutes, defaultChargeMinor, currency}. The
 *     controller performs NO partial select — the full model hydrates —
 *     so `facilityId` carries the real value (NOT NULL in the base
 *     schema) and `departmentId` is NULLABLE (the composite FK allows
 *     NULL — a service may be department-less); `department` is the eager
 *     ref {id, code, name} (null when departmentId is null or the
 *     relation cannot resolve — e.g. a soft-deleted department);
 *     `serviceType` ∈ opd_consultation/procedure/investigation/follow_up/
 *     other; `status` ∈ active/inactive; `defaultDurationMinutes`
 *     (integer) / `defaultChargeMinor` (integer minor units — never
 *     floats, DATABASE.md §0.4) / `currency` (3-char) all nullable. NO
 *     status filter — active AND inactive both presented. NO other related
 *     data, NO actor/correlation fields, NO tenant/timestamp/audit
 *     metadata;
 *  7. NO audit — ServiceController::index records no audit event
 *     (`service.created` / `service.updated` / `service.deleted` are
 *     write-side events only);
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

/** The eager department reference (the exact `with('department:id,code,name')` ref). */
export interface ServiceDepartmentRef {
  id: string;
  code: string;
  name: string;
}

/** One presented service (the exact ServiceController::index map). */
export interface ServiceRow {
  id: string;
  facilityId: string;
  departmentId: string | null;
  department: ServiceDepartmentRef | null;
  name: string;
  code: string;
  serviceType: string;
  status: string;
  defaultDurationMinutes: number | null;
  defaultChargeMinor: number | null;
  currency: string | null;
}

/** The NOT-FOUND classes a service read can produce (AccessCheck::organization). */
export type OrganizationServicesResult = ServiceRow[] | 'organization-not-found' | null;

export interface OrganizationsServicesDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped services read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's services (ordered by `name` ASC — the
   * exact `->orderBy('name')`, with the eager department ref
   * `with('department:id,code,name')`; the facility filter applied ONLY
   * when the caller has a facility claim; services is **TENANT_FACILITY**
   * — the select policy is `tenant_id = TENANT AND (facility_id =
   * FACILITY OR FACILITY IS NULL)` — NO branch clause (no branch_id
   * column, so a branch proposal does not narrow); **services ARE
   * soft-deletable** — the Laravel SoftDeletes model scope excludes
   * `deleted_at is not null` rows, reproduced exactly; NO status filter —
   * active AND inactive both return; `departmentId`/`defaultDurationMinutes`/
   * `defaultChargeMinor`/`currency` nullable). No mutation.
   */
  listOrganizationServices: (claims: Claims, organizationId: string) => OrganizationServicesResult;
}

export async function handleOrganizationsServices(req: Request, deps: OrganizationsServicesDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:service:view` — a DISTINCT
  // capability from the patient/insurance/consent/document/department/
  // branch/location/ward/room/bed/staff reads.
  if (!can(context, 'service:view')) {
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
  // Like the Phase 33/35/36/37/38/39 organization selectors there is NO
  // UUID gate: AccessCheck::organization resolves with `find($id)` and the
  // route binding is the Laravel 404 source for unknown ids. An
  // unknown/malformed id resolves to null at the dependency → the
  // AccessCheck-layer 404 'Organization not found.' below; a KNOWN
  // organization outside the caller's scope → the deny(read) 404
  // 'Resource not found.'.
  if (organizationId === '') {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS + AccessCheck decide visibility. The id is a resource selector —
  // never authorization scope.
  const services = deps.listOrganizationServices(claims, organizationId);

  if (services === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (services === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact ServiceController::index data shape: the bare service list
  // (already ordered by name ASC by the RLS-scoped read), wrapped in the
  // standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    services,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
