/**
 * The `encounters:charges` domain function (pure request handler, Phase 19) —
 * the posted charges of one encounter, mirroring the established Laravel
 * contract exactly (EncounterController::charges — the
 * `encounters/{encounter}/charges` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'billing:view')` capability
 *     (mirror of the Laravel route gate `authorize:billing:view` — the same
 *     gate as `invoices:show` / `invoices:payments`);
 *  3. the encounter id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the encounter lookup is claims-scoped (encounters is TENANT_FACILITY)
 *     and decides 404 semantics; the charges are read under the same claims
 *     (charges is TENANT_FACILITY) bound to the verified encounter id and
 *     ordered by charged_at ascending — the exact `->orderBy('charged_at')`;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): an encounter that does not exist AND an
 *     encounter that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved charge fields — the exact
 *     EncounterController::charges map: {id, sourceType, description,
 *     amountMinor, currency, status, chargedAt} (chargedAt nullable,
 *     `charged_at?->toIso8601String()`). All charges of the encounter are
 *     returned — including voided ones, whose status the client sees (the
 *     Laravel hasMany applies no status filter). No invoice/patient/related
 *     data — the contract includes none;
 *  7. NO audit — EncounterController::charges records no audit event
 *     (adding one would invent behavior);
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No pagination. No invented fields. No RLS weakening. No
 * SECURITY DEFINER. No service-role credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { encounterIdFromUrl } from './encounters_sign.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented charge (the exact EncounterController::charges map).
 * `chargedAt` mirrors `charged_at?->toIso8601String()` — nullable. */
export interface ChargeRow {
  id: string;
  sourceType: string;
  description: string;
  amountMinor: number;
  currency: string;
  status: string;
  chargedAt: string | null;
}

export interface EncountersChargesDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped charges read (swasthya_app under the claims; the
   * encounter id is a resource selector). Resolves the encounter under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the encounter's charges (ordered by
   * charged_at ascending, the exact `->orderBy('charged_at')`; all statuses,
   * including voided). No mutation.
   */
  listEncounterCharges: (claims: Claims, id: string) => ChargeRow[] | null;
}

export async function handleEncountersCharges(req: Request, deps: EncountersChargesDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:billing:view`.
  if (!can(context, 'billing:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const encounterId = encounterIdFromUrl(req);

  // A missing or malformed identifier is indistinguishable from a missing
  // resource — 404, never 400/422 (Laravel's implicit binding resolves to
  // the same ModelNotFoundException).
  if (encounterId === '' || !isUuid(encounterId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope encounter resolves to null here
  // and produces the SAME 404 as a nonexistent one (AccessCheck::scoped,
  // reads). The id is a resource selector — never authorization scope.
  const charges = deps.listEncounterCharges(claims, encounterId);

  if (charges === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact EncounterController::charges data shape: the bare charge list
  // (already ordered by charged_at by the RLS-scoped read), wrapped in the
  // standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    charges,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app encounter ids are UUIDs (the primary key of public.encounters). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
