/**
 * The `encounters:sign` domain function (pure request handler, Phase 14) —
 * the provider signs the completed encounter; signed encounters are
 * immutable history.
 *
 * It mirrors the established Laravel contract exactly
 * (EncounterController::sign). The Laravel behavior is the source of truth;
 * this function executes it through the secure pipeline + the same
 * RLS-scoped database.
 *
 * Clinical-safety + immutability invariants preserved:
 *   - the SIGNER is the authenticated identity whose active staff record is
 *     the encounter's provider (`currentProvider` parity) — 'Only the
 *     encounter provider can document this visit.'; never client-supplied;
 *   - ONLY an `open` encounter may be signed ('Only an open encounter can be
 *     signed (current status: X).'); a signed encounter is immutable — the
 *     guarded transition matches zero rows thereafter;
 *   - the encounter must contain AT LEAST ONE SIGNED clinical note before
 *     signing ('An encounter must contain at least one signed note before
 *     signing.') — the note author rule was already enforced at note-sign
 *     time; here only the existence of a signed note on the encounter
 *     matters (Laravel parity);
 *   - ended_at / signed_at / signed_by are generated SERVER-SIDE; status and
 *     lock_version are never client input;
 *   - the appointment handoff mirrors Laravel exactly: IF the encounter has
 *     an appointment AND it is `in_consultation`, it becomes `completed`
 *     (lock_version + 1); ANY other appointment state is a silent skip (no
 *     error) — parity with the conditional save.
 *
 * Flow:
 *  1. authenticate through the shared pipeline;
 *  2. authorize with `can(context, 'encounter:sign')` (route-gate parity);
 *  3. strict body contract: NO body is accepted — the signer has nothing
 *     authoritative to send (ended_at/signed_at/signed_by/status/lock_version
 *     are generated server-side);
 *  4. the encounter id is a RESOURCE SELECTOR only — out-of-scope/
 *     malformed → 404 (AccessCheck::scoped + implicit-binding parity);
 *  5. eligibility: encounter.status must be 'open' → else 409 with the exact
 *     Laravel message;
 *  6. required signed-note eligibility: at least one SIGNED note must exist
 *     on the encounter → else 409 with the exact Laravel message;
 *  7. author rule: the actor's staff must be the encounter's provider → else
 *     403 with the exact Laravel message;
 *  8. the GUARDED signing transaction (`status = 'signed', ended_at = now(),
 *     signed_at = now(), signed_by = actor, lock_version + 1 WHERE status =
 *     'open'`) plus the GUARDED appointment handoff (`status = 'completed'
 *     WHERE status = 'in_consultation'`, silent skip otherwise) — the DB
 *     decides the state atomically; a concurrent duplicate sign matches zero
 *     rows → 409;
 *  9. audit 'encounter.signed' (actor + authoritative tenant/facility +
 *     correlation id + patientId/providerStaffId/appointmentId);
 *  10. return 200 with the exact EncounterController::present shape.
 *
 * The client NEVER controls scope, provider, signed_by, ended_at, signed_at,
 * status, or lock_version; forged app_* claims and forged proposals are
 * inert. RLS (swasthya_app, NOBYPASSRLS) is the final boundary.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AuditEventInput } from './appointments_create.ts';
import { presentEncounter, type EncounterRow } from './encounters_create.ts';
import type { AuthorStaffRef } from './encounter_notes_draft.ts';

/** The encounter status values relevant to signing (model parity). */
export const ENCOUNTER_STATUS_OPEN = 'open';
export const ENCOUNTER_STATUS_SIGNED = 'signed';
/** The appointment status the handoff completes (model parity). */
export const APPOINTMENT_STATUS_IN_CONSULTATION = 'in_consultation';

export interface EncountersSignDeps extends HealthAuthDeps {
  /** RLS-scoped encounter lookup (swasthya_app under the claims); null covers
   * both nonexistent and out-of-scope (→ 404, existence never leaked). */
  findEncounterByScope: (claims: Claims, id: string) => EncounterRow | null;
  /**
   * Whether the encounter has AT LEAST ONE SIGNED clinical note
   * (EncounterController::sign parity — existence only; the note-author rule
   * was enforced at note-sign time). Runs claims-scoped.
   */
  hasSignedNote: (claims: Claims, encounterId: string) => boolean;
  /**
   * The actor's staff record, claims-scoped and tenant-bound (the
   * currentProvider rule — identical to the draft/sign paths): null → the
   * actor is NOT the encounter provider (→ 403).
   */
  findAuthorStaff: (
    claims: Claims,
    actorUserId: string,
    tenantId: string,
    providerStaffId: string,
  ) => AuthorStaffRef | null;
  /**
   * The ATOMIC signing transaction: guarded encounter transition
   * (`status = 'signed', ended_at = now(), signed_at = now(), signed_by =
   * actor, lock_version + 1 WHERE id = … AND tenant_id = … AND facility_id =
   * … AND status = 'open'`) then the GUARDED appointment handoff
   * (`status = 'completed' WHERE id = … AND status = 'in_consultation'` — a
   * zero-row handoff is a SILENT SKIP, exactly like the Laravel conditional
   * save). NOT_OPEN means the encounter guard matched zero rows (already
   * signed — including by a concurrent duplicate) and the whole transaction
   * rolled back. ended_at/signed_at are generated server-side.
   */
  signEncounter: (input: EncounterSignInput) => EncounterSignResult;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

export interface EncounterSignInput {
  tenantId: string;
  facilityId: string;
  encounterId: string;
  appointmentId: string | null;
  signedBy: string | null;
}

export type EncounterSignResult =
  | { ok: true; encounter: EncounterRow }
  | { ok: false; reason: 'NOT_OPEN' | 'ERROR' };

/** GoTrue/app encounter ids are UUIDs (the primary key of encounters). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The encounter id is the last non-empty URL segment (route parity). */
export function encounterIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

export async function handleEncountersSign(req: Request, deps: EncountersSignDeps): Promise<Response> {
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
  // is rejected — the client has nothing authoritative to send.
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

  const encounterId = encounterIdFromUrl(req);

  // Missing/malformed identifier ≡ missing resource → 404 (implicit-binding
  // parity with Laravel's encounters/{encounter}).
  if (encounterId === '' || !isUuid(encounterId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope encounter resolves to null and
  // produces the SAME 404 as a nonexistent one (AccessCheck::scoped).
  const encounter = deps.findEncounterByScope(claims, encounterId);
  if (encounter === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // Eligibility (friendly pre-checks — the guarded UPDATE is the real
  // arbiter for the status race; the signed-note set can only grow, never
  // shrink, so the existence check has no race window).
  if (encounter.status !== ENCOUNTER_STATUS_OPEN) {
    return errorEnvelope(
      ErrorCodes.CONFLICT,
      `Only an open encounter can be signed (current status: ${encounter.status}).`,
      409,
      correlationId,
    );
  }

  if (!deps.hasSignedNote(claims, encounterId)) {
    return errorEnvelope(
      ErrorCodes.CONFLICT,
      'An encounter must contain at least one signed note before signing.',
      409,
      correlationId,
    );
  }

  // Clinical-safety signing rule (currentProvider parity): the actor's active
  // staff record must BE the encounter's provider. Derived server-side.
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

  // The ATOMIC signing transaction: guarded encounter transition + guarded
  // appointment handoff (silent skip when the appointment is not
  // in_consultation — Laravel parity). NOT_OPEN → the exact same 409 a
  // sequential duplicate would get from the status gate above.
  const result = deps.signEncounter({
    tenantId,
    facilityId: encounter.facilityId,
    encounterId: encounter.id,
    appointmentId: encounter.appointmentId,
    signedBy: user.id,
  });

  if (!result.ok) {
    if (result.reason === 'NOT_OPEN') {
      return errorEnvelope(
        ErrorCodes.CONFLICT,
        `Only an open encounter can be signed (current status: ${encounter.status}).`,
        409,
        correlationId,
      );
    }
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. Facts only — no PHI.
  await deps.recordAudit({
    action: 'encounter.signed',
    resourceType: 'encounter',
    resourceId: result.encounter.id,
    actorId: user.id,
    tenantId,
    facilityId: encounter.facilityId,
    correlationId,
    payload: {
      patientId: encounter.patientId,
      providerStaffId: author.id,
      appointmentId: encounter.appointmentId,
    },
  });

  return successEnvelope(
    presentEncounter(result.encounter),
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
