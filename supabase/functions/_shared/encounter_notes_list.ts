/**
 * The `encounters:notes` domain function (pure request handler, Phase 25) —
 * the clinical notes of one encounter, mirroring the established Laravel
 * contract exactly (EncounterController::notes — the
 * `encounters/{encounter}/notes` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'encounter:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:encounter:view` — the same gate as `encounters:show`);
 *  3. the encounter id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the encounter lookup is claims-scoped (encounters is TENANT_FACILITY)
 *     and decides 404 semantics; the notes are read under the same claims
 *     (clinical_notes is TENANT_ONLY) bound to the verified encounter id and
 *     ordered by created_at ascending — the exact `->orderBy('created_at')`;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): an encounter that does not exist AND an
 *     encounter that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved note fields — the exact
 *     EncounterController::notes map: {id, noteType, author, content,
 *     status, signedAt}. `author` is the `{id, fullName}` ref of the
 *     eager-loaded `author:id,full_name` relation (resolved under the same
 *     claims — an out-of-scope author renders null, the established
 *     Phase 18/21 parity); `content` is the decoded jsonb (structured
 *     sections); `signedAt` nullable (`signed_at?->toIso8601String()`). ALL
 *     notes of the encounter return (draft, signed, amended — the Laravel
 *     hasMany applies no status filter);
 *  7. NO audit — EncounterController::notes records no audit event;
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

/** One presented note (the exact EncounterController::notes map). */
export interface EncounterNoteRow {
  id: string;
  noteType: string;
  author: { id: string; fullName: string } | null;
  content: Record<string, unknown>;
  status: string;
  signedAt: string | null;
}

export interface EncountersNotesDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped notes read (swasthya_app under the claims; the encounter
   * id is a resource selector). Resolves the encounter under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the encounter's clinical notes
   * (ordered by created_at ascending, the exact `->orderBy('created_at')`;
   * all statuses, including draft; the author ref resolved under the same
   * claims — an out-of-scope author renders null). No mutation.
   */
  listEncounterNotes: (claims: Claims, id: string) => EncounterNoteRow[] | null;
}

export async function handleEncountersNotes(req: Request, deps: EncountersNotesDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:encounter:view`.
  if (!can(context, 'encounter:view')) {
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
  const notes = deps.listEncounterNotes(claims, encounterId);

  if (notes === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact EncounterController::notes data shape: the bare note list
  // (already ordered by created_at by the RLS-scoped read), wrapped in the
  // standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    notes,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app encounter ids are UUIDs (the primary key of public.encounters). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
