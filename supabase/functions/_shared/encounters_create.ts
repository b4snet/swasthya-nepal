/**
 * The `encounters:create` domain function (pure request handler, Phase 11) —
 * encounter START / queue handoff, the next step of the M1 vertical slice.
 *
 * It mirrors the established Laravel contract exactly
 * (EncounterController::start + the uq_encounters_tenant_appointment partial
 * unique index). The Laravel behavior is the source of truth; this function
 * executes it through the secure pipeline + the same RLS-scoped database.
 *
 * Flow:
 *  1. authenticate through the shared pipeline (JWT → sub → users → status
 *     gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'encounter:create')`
 *     capability (mirror of the Laravel route gate `authorize:encounter:create`);
 *  3. the appointment id is a RESOURCE SELECTOR only — scope comes
 *     exclusively from the authoritative context/claims; not found AND
 *     out-of-scope AND malformed all → 404 (AccessCheck::scoped parity);
 *  4. eligibility: ONLY status 'checked_in' may start an encounter — every
 *     other status → 409 CONFLICT with the exact Laravel message;
 *  5. the atomic start dependency (injected) performs the guarded appointment
 *     transition (`status = 'in_consultation' WHERE status = 'checked_in'`,
 *     lock_version + 1) and the encounter INSERT in ONE transaction — two
 *     concurrent starts of one appointment yield exactly one success, the
 *     loser 409s, and the partial unique index
 *     uq_encounters_tenant_appointment is the DB-enforced backstop (the DB,
 *     never JS, decides);
 *  6. audit 'encounter.started' (actor + authoritative tenant/facility +
 *     correlation id + patient/appointment/provider facts);
 *  7. return 201 with the exact EncounterController::present shape.
 *
 * The client NEVER controls scope: tenant/facility/branch come exclusively
 * from the authoritative context/claims; forged app_* claims and forged
 * proposals are inert. RLS (swasthya_app, NOBYPASSRLS) is the final boundary.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AppointmentRow, AuditEventInput } from './appointments_create.ts';

/** The full RLS-visible encounter row (all fields `present` needs). */
export interface EncounterRow {
  id: string;
  facilityId: string;
  patientId: string;
  appointmentId: string | null;
  providerStaffId: string;
  type: string;
  status: string;
  startedAt: string | null;
  endedAt: string | null;
  signedAt: string | null;
  lockVersion: number;
}

export interface EncountersCreateDeps extends HealthAuthDeps {
  /**
   * RLS-scoped appointment lookup. Runs as swasthya_app under the claims;
   * `id` is a pure resource selector. null covers both nonexistent and
   * out-of-scope (→ 404, existence never leaked).
   */
  findAppointmentByScope: (claims: Claims, id: string) => AppointmentRow | null;
  /**
   * The ATOMIC start: in ONE transaction — set claims, apply the GUARDED
   * appointment transition (`status = 'in_consultation' WHERE
   * status = 'checked_in'`, lock_version + 1), then INSERT the encounter
   * (tenant/facility/patient/provider all from the appointment). NOT_CHECKED_IN
   * means the guarded update matched zero rows (already started / invalid
   * status — e.g. a concurrent duplicate start) and the whole transaction
   * rolled back; ALREADY_STARTED is the fail-closed backstop if the partial
   * unique index uq_encounters_tenant_appointment ever fires.
   */
  startEncounter: (input: EncounterStartInput) => EncounterStartResult;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

export interface EncounterStartInput {
  tenantId: string;
  facilityId: string;
  appointmentId: string;
  patientId: string;
  providerStaffId: string;
  startedBy: string | null;
}

export type EncounterStartResult =
  | { ok: true; encounter: EncounterRow }
  | { ok: false; reason: 'NOT_CHECKED_IN' | 'ALREADY_STARTED' | 'ERROR' };

/** GoTrue/app appointment ids are UUIDs (the primary key of appointments). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The appointment id is the last non-empty URL segment (route parity). */
export function appointmentIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

/** EncounterController::present parity — no embedded refs, ids only. */
export function presentEncounter(encounter: EncounterRow): Record<string, unknown> {
  return {
    id: encounter.id,
    facilityId: encounter.facilityId,
    patientId: encounter.patientId,
    appointmentId: encounter.appointmentId,
    providerStaffId: encounter.providerStaffId,
    type: encounter.type,
    status: encounter.status,
    startedAt: encounter.startedAt,
    endedAt: encounter.endedAt,
    signedAt: encounter.signedAt,
    lockVersion: encounter.lockVersion,
  };
}

export async function handleEncountersCreate(req: Request, deps: EncountersCreateDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization (defense-in-depth — RLS stays the final
  // boundary). Route-gate parity with `authorize:encounter:create`.
  if (!can(context, 'encounter:create')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  // Strict contract: encounter start takes NO request body. Any JSON body
  // with keys is rejected — the client has nothing authoritative to send.
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

  const appointmentId = appointmentIdFromUrl(req);

  // Missing/malformed identifier ≡ missing resource → 404 (implicit-binding
  // parity with Laravel's appointments/{appointment}).
  if (appointmentId === '' || !isUuid(appointmentId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope appointment resolves to null
  // and produces the SAME 404 as a nonexistent one (AccessCheck::scoped).
  const appointment = deps.findAppointmentByScope(claims, appointmentId);
  if (appointment === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // Eligibility gate (friendly pre-check — the guarded UPDATE is the real
  // arbiter). Only 'checked_in' may start an encounter.
  if (appointment.status !== 'checked_in') {
    return errorEnvelope(
      ErrorCodes.CONFLICT,
      `An encounter can only be started from a checked-in appointment (current status: ${appointment.status}).`,
      409,
      correlationId,
    );
  }

  // Atomic start: guarded appointment transition + encounter INSERT in ONE
  // transaction. NOT_CHECKED_IN here means the guarded update matched zero
  // rows — a concurrent start already moved the appointment (the exact same
  // 409 the sequential duplicate would get from the status gate above).
  const result = deps.startEncounter({
    tenantId: context.organizationId ?? '',
    facilityId: appointment.facilityId,
    appointmentId,
    patientId: appointment.patientId,
    providerStaffId: appointment.providerStaffId,
    startedBy: user.id,
  });

  if (!result.ok) {
    if (result.reason === 'NOT_CHECKED_IN' || result.reason === 'ALREADY_STARTED') {
      return errorEnvelope(
        ErrorCodes.CONFLICT,
        `An encounter can only be started from a checked-in appointment (current status: ${appointment.status}).`,
        409,
        correlationId,
      );
    }
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. Facts only — no PHI.
  await deps.recordAudit({
    action: 'encounter.started',
    resourceType: 'encounter',
    resourceId: result.encounter.id,
    actorId: user.id,
    tenantId: context.organizationId ?? '',
    facilityId: appointment.facilityId,
    correlationId,
    payload: {
      patientId: appointment.patientId,
      appointmentId: appointment.id,
      providerStaffId: appointment.providerStaffId,
    },
  });

  return successEnvelope(
    presentEncounter(result.encounter),
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
    201,
  );
}
