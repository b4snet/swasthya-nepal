/**
 * The `organizations:medications` domain function (pure request handler, Phase 42)
 * — the organization-scoped formulary read, mirroring the established
 * Laravel contract exactly (MedicationController::index — the
 * `organizations/{organization}/medications` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'medication:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:medication:view` — a DISTINCT capability from the
 *     patient/insurance/consent/document/department/branch/location/ward/
 *     room/bed/staff/service/payer reads. The RolePermissionSeeder grants
 *     it to support_agent / org_admin / hospital_admin / branch_manager /
 *     doctor / nurse — the seeded receptionist / billing_clerk roles do
 *     NOT hold it). A principal with the related view permissions alone is
 *     DENIED — the gate is `medication:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant/facility scope comes exclusively from
 *     the authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33–41 reads): an
 *     organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the medications are read under the claims (medications is
 *     **TENANT_FACILITY** — NOT TENANT_FACILITY_BRANCH: medications has NO
 *     branch_id column, so the select policy is `tenant_id = TENANT AND
 *     (facility_id = FACILITY OR FACILITY IS NULL)` — there is NO branch
 *     clause), bound to the verified organization id, and ordered by
 *     `generic_name` ascending — the exact `->orderBy('generic_name')`.
 *     The facility filter is applied ONLY when the caller has a facility
 *     scope (the exact `! $context->isPlatform && $context->facilityId()
 *     !== null` guard — org-level / platform callers see every facility of
 *     the tenant). A branch proposal is IRRELEVANT to this read —
 *     medications has no branch dimension. **Medications ARE
 *     soft-deletable** — the Laravel model uses SoftDeletes, so the read
 *     excludes soft-deleted rows (`deleted_at is null`) exactly as the
 *     model's default scope does;
 *  6. present ONLY the approved medication fields — the exact
 *     MedicationController::index map: {id, facilityId, code, genericName,
 *     brandName, strength, form, unit, priceMinor, currency, isControlled,
 *     status}. The controller performs NO partial select — the full model
 *     hydrates — so `facilityId` carries the real value (NOT NULL in the
 *     base schema); `brandName` is NULLABLE (the only nullable text
 *     field); `strength`/`form`/`unit` are NOT NULL (form defaults to
 *     'tablet'); `priceMinor` is an integer in minor units (never floats,
 *     DATABASE.md §0.4) with a `price_minor >= 0` CHECK; `currency` is the
 *     3-char ISO code (default 'NPR'); `isControlled` is a boolean; the
 *     `lock_version` counter exists in the schema but is NEVER presented.
 *     NO status filter — active AND inactive both presented. NO other
 *     related data, NO actor/correlation fields, NO tenant/timestamp/audit
 *     metadata;
 *  7. NO audit — MedicationController::index records no audit event
 *     (`medication.created` is the only audit path, write-side);
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

/** One presented medication (the exact MedicationController::index map). */
export interface MedicationRow {
  id: string;
  facilityId: string;
  code: string;
  genericName: string;
  brandName: string | null;
  strength: string;
  form: string;
  unit: string;
  priceMinor: number;
  currency: string;
  isControlled: boolean;
  status: string;
}

/** The NOT-FOUND classes a medication read can produce (AccessCheck::organization). */
export type OrganizationMedicationsResult = MedicationRow[] | 'organization-not-found' | null;

export interface OrganizationsMedicationsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped medications read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's medications (ordered by `generic_name` ASC
   * — the exact `->orderBy('generic_name')`; the facility filter applied
   * ONLY when the caller has a facility claim; medications is
   * **TENANT_FACILITY** — the select policy is `tenant_id = TENANT AND
   * (facility_id = FACILITY OR FACILITY IS NULL)` — NO branch clause (no
   * branch_id column, so a branch proposal does not narrow);
   * **medications ARE soft-deletable** — the Laravel SoftDeletes model
   * scope excludes `deleted_at is not null` rows, reproduced exactly; NO
   * status filter — active AND inactive both return; `brandName` nullable,
   * the rest of the text fields NOT NULL; `priceMinor` integer minor
   * units). No mutation.
   */
  listOrganizationMedications: (claims: Claims, organizationId: string) => OrganizationMedicationsResult;
}

export async function handleOrganizationsMedications(req: Request, deps: OrganizationsMedicationsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:medication:view` — a
  // DISTINCT capability from the patient/insurance/consent/document/
  // department/branch/location/ward/room/bed/staff/service/payer reads.
  if (!can(context, 'medication:view')) {
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
  // Like the Phase 33–41 organization selectors there is NO UUID gate:
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
  const medications = deps.listOrganizationMedications(claims, organizationId);

  if (medications === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (medications === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact MedicationController::index data shape: the bare medication
  // list (already ordered by generic_name ASC by the RLS-scoped read),
  // wrapped in the standard envelope. No audit — the Laravel read does not
  // audit.
  return successEnvelope(
    medications,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
