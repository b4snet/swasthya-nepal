/**
 * The `organizations:payers` domain function (pure request handler, Phase 41)
 * — the organization-scoped payer-catalog read, mirroring the established
 * Laravel contract exactly (PayerController::index — the
 * `organizations/{organization}/payers` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'payer:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:payer:view` — a DISTINCT capability from the
 *     patient/insurance/consent/document/department/branch/location/ward/
 *     room/bed/staff/service reads; held by the support_agent / org_admin /
 *     org_finance / hospital_admin / branch_manager / receptionist /
 *     billing_clerk roles in the RolePermissionSeeder — the seeded doctor /
 *     nurse roles do NOT hold it). A principal with the related view
 *     permissions alone is DENIED — the gate is `payer:view`;
 *  3. the organization id is a RESOURCE SELECTOR only — never
 *     authorization scope. Tenant scope comes exclusively from the
 *     authoritative context/claims;
 *  4. NOT-FOUND semantics mirror `AccessCheck::organization($key,
 *     write: false)` EXACTLY (identical to the Phase 33/35/36/37/38/39/40
 *     reads): an organization that does not exist →
 *     `404 NOT_FOUND` **'Organization not found.'** (the ApiException
 *     thrown directly by AccessCheck); an organization that exists but is
 *     outside the caller's scope → `deny(read)` →
 *     `404 NOT_FOUND` **'Resource not found.'** (existence is never
 *     leaked). Platform callers bypass the scope check. A missing selector
 *     → `404 NOT_FOUND` 'Resource not found.';
 *  5. the RLS-scoped read runs as swasthya_app with request.jwt.claims
 *     set: the payers are read under the claims (payers is **TENANT_ONLY**
 *     — the SIMPLEST tier: payers are TENANT-WIDE, NOT facility-scoped — a
 *     policy covers a patient at any facility of the tenant, so the select
 *     policy is `tenant_id = TENANT` with NO facility clause AND NO branch
 *     clause; the Laravel query itself has NO facility filter — the `!
 *     $context->isPlatform && $context->facilityId() !== null` guard is
 *     ABSENT from PayerController::index, so a facility-scoped caller sees
 *     every payer of the tenant), bound to the verified organization id,
 *     and ordered by `name` ascending — the exact `->orderBy('name')`. A
 *     facility or branch proposal is IRRELEVANT to this read — payers has
 *     no facility/branch dimension. Payers are NOT soft-deletable (no
 *     SoftDeletes trait, no deleted_at column — status is the lifecycle);
 *  6. present ONLY the approved payer fields — the exact
 *     PayerController::index map: {id, name, code, payerType, status}.
 *     `name`/`code` (string(50))/`payerType`/`status` are NOT NULL in the
 *     base schema; `payerType` ∈ government/private/tpa/other (the
 *     `payer_type` column maps to `payerType`); `status` ∈ active/inactive.
 *     NO status filter — active AND inactive both presented. NO
 *     relationships, NO financial/monetary fields (payer rates live on the
 *     insurance policies, not the payer master), NO actor/correlation
 *     fields, NO tenant/timestamp/audit metadata;
 *  7. NO audit — PayerController::index records no audit event
 *     (`payer.created` / `payer.updated` are write-side events only);
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

/** One presented payer (the exact PayerController::index map). */
export interface PayerRow {
  id: string;
  name: string;
  code: string;
  payerType: string;
  status: string;
}

/** The NOT-FOUND classes a payer read can produce (AccessCheck::organization). */
export type OrganizationPayersResult = PayerRow[] | 'organization-not-found' | null;

export interface OrganizationsPayersDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped payers read (swasthya_app under the claims; the
   * organization id is a resource selector). Resolves the organization
   * first:
   *   - a nonexistent organization → `'organization-not-found'` → the
   *     404 **'Organization not found.'** (AccessCheck::organization's
   *     own ApiException);
   *   - an organization outside the authoritative tenant claim (no
   *     assignment whose tenant_id equals the organization key; platform
   *     callers bypass — AccessCheck::organization) → `null` → the 404
   *     'Resource not found.' (deny(read) — existence is never leaked);
   * Returns the organization's payers (ordered by `name` ASC — the exact
   * `->orderBy('name')`; payers is **TENANT_ONLY** — tenant-wide, NOT
   * facility-scoped — so the select policy is `tenant_id = TENANT` with NO
   * facility clause AND the Laravel query itself has NO facility filter: a
   * facility-scoped caller sees every payer of the tenant; NO branch
   * dimension; NO status filter — active AND inactive both return; payers
   * are NOT soft-deletable — no deleted_at filter, status is the
   * lifecycle). No mutation.
   */
  listOrganizationPayers: (claims: Claims, organizationId: string) => OrganizationPayersResult;
}

export async function handleOrganizationsPayers(req: Request, deps: OrganizationsPayersDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). The exact Laravel gate `authorize:payer:view` — a DISTINCT
  // capability from the patient/insurance/consent/document/department/
  // branch/location/ward/room/bed/staff/service reads.
  if (!can(context, 'payer:view')) {
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
  // Like the Phase 33/35/36/37/38/39/40 organization selectors there is NO
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
  const payers = deps.listOrganizationPayers(claims, organizationId);

  if (payers === 'organization-not-found') {
    // AccessCheck::organization's own NOT_FOUND — the organization does
    // not exist (distinct from the out-of-scope denial below).
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Organization not found.', 404, correlationId);
  }

  if (payers === null) {
    // deny(read): the organization exists but is outside the caller's
    // scope — the generic denial 404.
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact PayerController::index data shape: the bare payer list
  // (already ordered by name ASC by the RLS-scoped read), wrapped in the
  // standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    payers,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
