/**
 * The `encounter-notes:draft` domain function (pure request handler,
 * Phase 12) — the first CLINICAL-DOCUMENTATION write on the shared pipeline.
 *
 * It mirrors the established Laravel contract exactly
 * (EncounterController::storeNote + StoreClinicalNoteRequest). The Laravel
 * behavior is the source of truth; this function executes it through the
 * secure pipeline + the same RLS-scoped database.
 *
 * Clinical-safety invariants preserved (MASTER_RULES §8.4 / §12.5):
 *   - author_staff_id is ALWAYS the encounter's provider staff row — derived
 *     from the authenticated identity, NEVER from the client;
 *   - only the assigned clinician may document their own visit
 *     (`currentProvider` parity): the actor's active staff record must BE the
 *     encounter's provider, else 403 SCOPE_DENIED with the exact Laravel
 *     message;
 *   - drafts only: an encounter that is not `open` (signed/amended/closed)
 *     rejects all clinical content with the exact Laravel 409 message — the
 *     signed/finalized immutability boundary is preserved;
 *   - content is a structured JSON object of sections (never free-form
 *     blob), note_type restricted to the schema's check constraint values.
 *
 * Flow:
 *  1. authenticate through the shared pipeline;
 *  2. authorize with `can(context, 'encounter:document')` (route-gate parity);
 *  3. strict body contract: noteType (optional, enum) + content (required
 *     non-empty object of string/null sections ≤10000 chars each), unknown
 *     fields rejected (ApiRequest strict-mode parity);
 *  4. the encounter id is a RESOURCE SELECTOR only — out-of-scope ≡
 *     nonexistent ≡ malformed → 404 (AccessCheck::scoped parity);
 *  5. eligibility: encounter.status must be 'open' → else 409 with the exact
 *     Laravel message (draft/finalized boundary);
 *  6. resolve the actor's staff record under the claims and require it to be
 *     the encounter's provider (author = provider rule);
 *  7. insert the draft note (tenant/encounter/author all server-derived;
 *     status 'draft', lock_version 0) through the RLS-scoped dependency;
 *  8. audit 'note.drafted' (actor + authoritative tenant/facility +
 *     correlation id + encounterId/noteType/authorStaffId);
 *  9. return 201 with the exact storeNote response shape.
 *
 * The client NEVER controls scope, author, provider, tenant, or facility;
 * forged app_* claims and forged proposals are inert. RLS (swasthya_app,
 * NOBYPASSRLS) is the final boundary.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AuditEventInput } from './appointments_create.ts';
import type { EncounterRow } from './encounters_create.ts';

/** The note_type values allowed by chk_clinical_notes_type (schema parity). */
export const NOTE_TYPES = ['consultation', 'nursing', 'procedure', 'progress', 'discharge', 'other'] as const;
export const DEFAULT_NOTE_TYPE = 'consultation';

/** The draft status value (chk_clinical_notes_status + model parity). */
export const NOTE_STATUS_DRAFT = 'draft';

/** Max length of one structured content section (validator parity). */
export const CONTENT_SECTION_MAX = 10_000;

/** The full RLS-visible clinical note row (all fields `present` needs). */
export interface NoteRow {
  id: string;
  encounterId: string | null;
  noteType: string;
  authorStaffId: string;
  content: Record<string, unknown>;
  status: string;
}

/** The actor's staff identity (mirror of Staff::present-ish refs). */
export interface AuthorStaffRef {
  id: string;
  fullName: string;
  facilityId: string;
}

export interface EncounterNotesDraftDeps extends HealthAuthDeps {
  /**
   * RLS-scoped encounter lookup (swasthya_app under the claims). `id` is a
   * pure resource selector; null covers both nonexistent and out-of-scope
   * (→ 404, existence never leaked). Runs as swasthya_app (NOBYPASSRLS).
   */
  findEncounterByScope: (claims: Claims, id: string) => EncounterRow | null;
  /**
   * The actor's staff record, claims-scoped and tenant-bound: the row where
   * staff.user_id = actor AND staff.id = providerStaffId AND staff.tenant_id
   * = tenantId AND staff.status <> 'departed'. null → the actor is NOT the
   * encounter provider (→ 403, `currentProvider` parity — the client cannot
   * supply author_staff_id).
   */
  findAuthorStaff: (
    claims: Claims,
    actorUserId: string,
    tenantId: string,
    providerStaffId: string,
  ) => AuthorStaffRef | null;
  /** The draft INSERT (server-derived fields only; plain insert — multiple
   * drafts per encounter are permitted by the schema, no unique race). */
  createDraftNote: (input: NoteDraftInput) => NoteDraftResult;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

export interface NoteDraftInput {
  tenantId: string;
  facilityId: string;
  encounterId: string;
  noteType: string;
  content: Record<string, unknown>;
  authorStaffId: string;
  createdBy: string | null;
}

export type NoteDraftResult = { ok: true; note: NoteRow } | { ok: false; reason: 'ERROR' };

/** GoTrue/app encounter ids are UUIDs (the primary key of encounters). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The encounter id is the last non-empty URL segment (route parity). */
export function encounterIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

/** StoreClinicalNoteRequest parity: content is a non-empty OBJECT of
 * string-or-null sections, each ≤10000 chars. Arrays/lists are rejected. */
export function validateContent(value: unknown): value is Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false;
  }
  const entries = Object.entries(value as Record<string, unknown>);
  if (entries.length < 1) {
    return false;
  }
  for (const [, section] of entries) {
    if (section !== null && typeof section !== 'string') {
      return false;
    }
    if (typeof section === 'string' && section.length > CONTENT_SECTION_MAX) {
      return false;
    }
  }
  return true;
}

/** EncounterController::storeNote response parity (ids + author ref only). */
export function presentNote(note: NoteRow, author: AuthorStaffRef): Record<string, unknown> {
  return {
    id: note.id,
    noteType: note.noteType,
    author: { id: author.id, fullName: author.fullName },
    content: note.content,
    status: note.status,
  };
}

export async function handleEncounterNotesDraft(req: Request, deps: EncounterNotesDraftDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization (defense-in-depth — RLS stays the final
  // boundary). Route-gate parity with `authorize:encounter:document`.
  if (!can(context, 'encounter:document')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  // Strict request-body contract (ApiRequest strict-mode parity).
  let bodyText = '';
  try {
    bodyText = await req.text();
  } catch {
    bodyText = '';
  }

  let body: unknown;
  if (bodyText.trim() === '') {
    return errorEnvelope(ErrorCodes.VALIDATION_ERROR, '1 field(s) failed validation.', 422, correlationId, [
      { field: 'content', code: 'REQUIRED', message: 'The content field is required.' },
    ]);
  }
  try {
    body = JSON.parse(bodyText);
  } catch {
    return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
  }
  if (typeof body !== 'object' || body === null || Array.isArray(body)) {
    return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
  }

  const fields = Object.keys(body as Record<string, unknown>);
  const details: { field: string; code: string; message: string }[] = [];

  for (const field of fields) {
    if (field !== 'noteType' && field !== 'content') {
      details.push({ field, code: 'NOT_ALLOWED', message: `Field "${field}" is not allowed.` });
    }
  }

  const noteTypeValue = (body as Record<string, unknown>).noteType;
  let noteType = DEFAULT_NOTE_TYPE;
  if (noteTypeValue !== undefined) {
    if (typeof noteTypeValue !== 'string' || !NOTE_TYPES.includes(noteTypeValue as (typeof NOTE_TYPES)[number])) {
      details.push({
        field: 'noteType',
        code: 'INVALID',
        message: 'The selected noteType is invalid.',
      });
    } else {
      noteType = noteTypeValue;
    }
  }

  const contentValue = (body as Record<string, unknown>).content;
  if (!validateContent(contentValue)) {
    details.push({
      field: 'content',
      code: 'INVALID',
      message: 'The content must be a non-empty object of text sections (max 10000 characters each).',
    });
  }

  if (details.length > 0) {
    return errorEnvelope(
      ErrorCodes.VALIDATION_ERROR,
      `${details.length} field(s) failed validation.`,
      422,
      correlationId,
      details,
    );
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

  // Draft/finalized boundary: clinical content may only be added to an OPEN
  // encounter (guardNotSigned parity — the exact Laravel message).
  if (encounter.status !== 'open') {
    return errorEnvelope(
      ErrorCodes.CONFLICT,
      'Clinical content cannot be added to a signed encounter — amendment is the only path (later phase).',
      409,
      correlationId,
    );
  }

  // Clinical-safety author rule (currentProvider parity): the actor's active
  // staff record must BE the encounter's provider. The client can never
  // supply author_staff_id — it is derived server-side from the identity.
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

  // Plain INSERT — multiple drafts per encounter are permitted by the
  // schema (no unique index); there is no race to arbitrate.
  const result = deps.createDraftNote({
    tenantId,
    facilityId: encounter.facilityId,
    encounterId: encounter.id,
    noteType,
    content: contentValue as Record<string, unknown>,
    authorStaffId: author.id,
    createdBy: user.id,
  });

  if (!result.ok) {
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. Facts only — no PHI.
  await deps.recordAudit({
    action: 'note.drafted',
    resourceType: 'clinical_note',
    resourceId: result.note.id,
    actorId: user.id,
    tenantId,
    facilityId: encounter.facilityId,
    correlationId,
    payload: {
      encounterId: encounter.id,
      noteType: result.note.noteType,
      authorStaffId: author.id,
    },
  });

  return successEnvelope(
    presentNote(result.note, author),
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
    201,
  );
}
