/**
 * The `encounter-notes:sign` domain function (pure request handler,
 * Phase 13) — the note signing endpoint on the shared pipeline.
 *
 * It mirrors the established Laravel contract exactly
 * (EncounterController::signNote). The Laravel behavior is the source of
 * truth; this function executes it through the secure pipeline + the same
 * RLS-scoped database.
 *
 * Clinical-safety + immutability invariants preserved:
 *   - the SIGNER is the authenticated identity whose active staff record is
 *     the encounter's provider (`currentProvider` parity), AND that same
 *     staff row must be the note's author_staff_id — 'Only the note author
 *     can sign it.' The client can never supply author/provider identity;
 *   - ONLY a `draft` note may be signed ('Only a draft note can be signed.');
 *     a signed note is immutable — re-signing is impossible (the guarded
 *     transition matches zero rows);
 *   - signed_at is generated SERVER-SIDE (now()); status and lock_version
 *     are never client input;
 *   - note the SIGN path deliberately has NO encounter-status guard in the
 *     Laravel implementation (unlike the draft path): a draft note may be
 *     signed regardless of the encounter's status. Preserved as-is.
 *
 * Flow:
 *  1. authenticate through the shared pipeline;
 *  2. authorize with `can(context, 'encounter:sign')` (route-gate parity);
 *  3. strict body contract: NO body is accepted (the signer has nothing
 *     authoritative to send) — non-empty JSON with fields → 422, malformed
 *     JSON → 400;
 *  4. the encounter id and note id are RESOURCE SELECTORS only — out-of-
 *     scope/malformed → 404 (AccessCheck::scoped + implicit-binding parity);
 *  5. the note must belong to the encounter (404 'Note not found on this
 *     encounter.' parity);
 *  6. eligibility: note.status must be 'draft' → else 409 with the exact
 *     Laravel message;
 *  7. author rules: the actor's staff must be the encounter's provider (403
 *     'Only the encounter provider can document this visit.') AND the note's
 *     author_staff_id must be that provider (403 'Only the note author can
 *     sign it.');
 *  8. the GUARDED signing transition (`status = 'signed', signed_at = now(),
 *     lock_version + 1 WHERE status = 'draft'`) — the DB decides the state
 *     atomically; a concurrent duplicate sign matches zero rows → 409;
 *  9. audit 'note.signed' (actor + authoritative tenant/facility +
 *     correlation id + encounterId/authorStaffId);
 *  10. return 200 with the exact signNote response shape {id, status,
 *      signedAt}.
 *
 * The client NEVER controls scope, author, provider, signed_at, status, or
 * lock_version; forged app_* claims and forged proposals are inert. RLS
 * (swasthya_app, NOBYPASSRLS) is the final boundary.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AuditEventInput } from './appointments_create.ts';
import type { EncounterRow } from './encounters_create.ts';
import type { AuthorStaffRef } from './encounter_notes_draft.ts';

/** The draft status value (chk_clinical_notes_status + model parity). */
export const NOTE_STATUS_DRAFT = 'draft';
/** The signed status value (model parity). */
export const NOTE_STATUS_SIGNED = 'signed';

/** The full RLS-visible clinical note row needed by the sign contract. */
export interface NoteSignRow {
  id: string;
  encounterId: string | null;
  authorStaffId: string;
  status: string;
  signedAt: string | null;
  lockVersion: number;
}

export interface EncounterNotesSignDeps extends HealthAuthDeps {
  /** RLS-scoped encounter lookup (swasthya_app under the claims); null covers
   * both nonexistent and out-of-scope (→ 404, existence never leaked). */
  findEncounterByScope: (claims: Claims, id: string) => EncounterRow | null;
  /**
   * The actor's staff record, claims-scoped and tenant-bound (the
   * currentProvider rule — identical to the draft path): null → the actor is
   * NOT the encounter provider (→ 403).
   */
  findAuthorStaff: (
    claims: Claims,
    actorUserId: string,
    tenantId: string,
    providerStaffId: string,
  ) => AuthorStaffRef | null;
  /**
   * RLS-scoped note lookup bound to the encounter: the row where
   * clinical_notes.id = noteId AND clinical_notes.encounter_id =
   * encounterId, visible under the claims. null covers nonexistent,
   * out-of-scope, and note-of-a-different-encounter (→ 404 'Note not found
   * on this encounter.' — existence never leaked).
   */
  findNoteByScope: (claims: Claims, encounterId: string, noteId: string) => NoteSignRow | null;
  /**
   * The ATOMIC guarded signing transition: `status = 'signed',
   * signed_at = now(), lock_version = lock_version + 1 WHERE id = … AND
   * tenant_id = … AND encounter_id = … AND status = 'draft'` in ONE
   * transaction. NOT_DRAFT means the guard matched zero rows (already signed
   * — including by a concurrent duplicate request) and the transaction
   * rolled back; signed_at is generated server-side.
   */
  signNote: (input: NoteSignInput) => NoteSignResult;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

export interface NoteSignInput {
  tenantId: string;
  facilityId: string;
  encounterId: string;
  noteId: string;
  signedBy: string | null;
}

export type NoteSignResult =
  | { ok: true; note: NoteSignRow }
  | { ok: false; reason: 'NOT_DRAFT' | 'ERROR' };

/** GoTrue/app ids are UUIDs (the primary keys of encounters/clinical_notes). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/**
 * The two resource selectors from the URL: the note id is the last segment,
 * the encounter id the one before it (route parity with
 * encounters/{encounter}/notes/{note}/sign).
 */
export function idsFromUrl(req: Request): { encounterId: string; noteId: string } {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return {
    encounterId: segments.length > 1 ? segments[segments.length - 2] : '',
    noteId: segments.length > 0 ? segments[segments.length - 1] : '',
  };
}

/** EncounterController::signNote response parity. */
export function presentSignedNote(note: NoteSignRow): Record<string, unknown> {
  return {
    id: note.id,
    status: note.status,
    signedAt: note.signedAt,
  };
}

export async function handleEncounterNotesSign(req: Request, deps: EncounterNotesSignDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization (defense-in-depth — RLS stays the final
  // boundary). Route-gate parity with `authorize:encounter:sign`.
  if (!can(context, 'encounter:sign')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  // Strict contract: signing takes NO request body. Any JSON body with keys
  // is rejected — the client has nothing authoritative to send (signed_at,
  // status, and lock_version are generated server-side).
  let bodyText = '';
  try {
    bodyText = await req.text();
  } catch {
    bodyText = '';
  }
  if (bodyText.trim() !== '') {
    let parsed: unknown;
    try {
      parsed = JSON.parse(bodyText);
    } catch {
      return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
    }
    if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
      return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
    }
    const fields = Object.keys(parsed as Record<string, unknown>);
    if (fields.length > 0) {
      return errorEnvelope(
        ErrorCodes.VALIDATION_ERROR,
        `${fields.length} field(s) failed validation.`,
        422,
        correlationId,
        fields.map((field) => ({
          field,
          code: 'NOT_ALLOWED',
          message: `Field "${field}" is not allowed.`,
        })),
      );
    }
  }

  const { encounterId, noteId } = idsFromUrl(req);

  // Missing/malformed identifiers ≡ missing resources → 404 (implicit-binding
  // parity with encounters/{encounter} and notes/{note}).
  if (encounterId === '' || noteId === '' || !isUuid(encounterId) || !isUuid(noteId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope encounter resolves to null and
  // produces the SAME 404 as a nonexistent one (AccessCheck::scoped).
  const encounter = deps.findEncounterByScope(claims, encounterId);
  if (encounter === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The note must exist, be visible, AND belong to this encounter — a note of
  // a different encounter (or an out-of-scope/nonexistent note) is the SAME
  // 404 (note-encounter binding parity).
  const note = deps.findNoteByScope(claims, encounterId, noteId);
  if (note === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Note not found on this encounter.', 404, correlationId);
  }

  // Eligibility (friendly pre-check — the guarded UPDATE is the real
  // arbiter): ONLY a draft note may be signed.
  if (note.status !== NOTE_STATUS_DRAFT) {
    return errorEnvelope(ErrorCodes.CONFLICT, 'Only a draft note can be signed.', 409, correlationId);
  }

  // Clinical-safety author rules (currentProvider + note-author parity): the
  // actor's active staff record must BE the encounter's provider, AND that
  // provider must be the note's author. Both are derived server-side.
  const tenantId = context.organizationId ?? '';
  const author = deps.findAuthorStaff(claims, user.id, tenantId, encounter.providerStaffId);
  if (author === null) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'Only the encounter provider can document this visit.',
      403,
      correlationId,
    );
  }

  if (note.authorStaffId !== author.id) {
    return errorEnvelope(ErrorCodes.SCOPE_DENIED, 'Only the note author can sign it.', 403, correlationId);
  }

  // The ATOMIC guarded transition: the DB decides the state — a concurrent
  // duplicate sign (or any earlier transition) matches zero rows and the
  // whole transaction rolls back. NOT_DRAFT → the exact same 409 a sequential
  // duplicate would get from the status gate above.
  const result = deps.signNote({
    tenantId,
    facilityId: encounter.facilityId,
    encounterId: encounter.id,
    noteId,
    signedBy: user.id,
  });

  if (!result.ok) {
    if (result.reason === 'NOT_DRAFT') {
      return errorEnvelope(ErrorCodes.CONFLICT, 'Only a draft note can be signed.', 409, correlationId);
    }
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. Facts only — no PHI.
  await deps.recordAudit({
    action: 'note.signed',
    resourceType: 'clinical_note',
    resourceId: result.note.id,
    actorId: user.id,
    tenantId,
    facilityId: encounter.facilityId,
    correlationId,
    payload: {
      encounterId: encounter.id,
      authorStaffId: author.id,
    },
  });

  return successEnvelope(
    presentSignedNote(result.note),
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
