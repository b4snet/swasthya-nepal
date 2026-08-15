/**
 * The `appointments:checkin` domain function (pure request handler,
 * Phase 10) — the SECOND write endpoint on the shared pipeline.
 *
 * It is NOT a greenfield queue workflow: it mirrors the established Laravel
 * contract exactly (AppointmentController::checkIn + TokenIssuer + the
 * token_counters row lock + uq_token_counters_tenant_facility_provider_date).
 *
 * Flow:
 *  1. authenticate through the shared pipeline (JWT → sub → users → status
 *     gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'appointment:checkin')`
 *     capability (mirror of the Laravel route gate);
 *  3. the appointment id is a RESOURCE SELECTOR only — scope comes
 *     exclusively from the authoritative context/claims; not found AND
 *     out-of-scope AND malformed all → 404 (AccessCheck::scoped parity);
 *  4. eligibility: ONLY status 'booked' may be checked in — every other
 *     status → 409 CONFLICT with the exact Laravel message;
 *  5. the atomic check-in dependency (injected) mints the next queue token
 *     by ROW-LOCKING the token_counters row (per tenant+facility+provider+
 *     date) and then applies a GUARDED status transition
 *     (`status = 'checked_in' WHERE status = 'booked'`) in the SAME
 *     transaction — two concurrent check-ins of one appointment yield
 *     exactly one success, and parallel check-ins of the same provider/day
 *     can never receive the same token (the DB, never JS, decides);
 *  6. audit 'appointment.checked_in' (actor + authoritative tenant/facility
 *     + correlation id + token);
 *  7. return the exact AppointmentController::present shape (same as
 *     appointments:create, now with status checked_in + tokenNo populated).
 *
 * The client NEVER controls scope; forged app_* claims and forged proposals
 * are inert. RLS (swasthya_app, NOBYPASSRLS) is the final boundary — the
 * token counter itself lives in a claims-scoped table.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import {
  presentAppointment,
  type AppointmentRow,
  type AuditEventInput,
  type PatientRef,
  type ProviderRef,
} from './appointments_create.ts';

/** The full RLS-visible appointment row (all fields `present` needs). */
export interface CheckinDeps extends HealthAuthDeps {
  /**
   * RLS-scoped appointment lookup. Runs as swasthya_app under the claims;
   * `id` is a pure resource selector. null covers both nonexistent and
   * out-of-scope (→ 404, existence never leaked).
   */
  findAppointmentByScope: (claims: Claims, id: string) => AppointmentRow | null;
  /** RLS-scoped patient lookup (for the presentation refs, like create). */
  findPatientByScope: (claims: Claims, patientId: string) => PatientRef | null;
  /** RLS-scoped provider (staff) lookup (for the presentation refs). */
  findProviderByScope: (claims: Claims, providerStaffId: string) => ProviderRef | null;
  /**
   * The ATOMIC check-in: in ONE transaction — set claims, lock the
   * token_counters row for (tenant, facility, provider, date), mint the next
   * token, and apply the GUARDED status transition
   * (`WHERE id = … AND status = 'booked'`). NOT_BOOKED means the guarded
   * update matched zero rows (concurrent duplicate check-in or a status
   * change between the eligibility read and the write) — the whole
   * transaction is rolled back, so no token is wasted and no partial
   * mutation survives.
   */
  checkInAppointment: (input: CheckinInput) => CheckinResult;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

export interface CheckinInput {
  tenantId: string;
  facilityId: string;
  appointmentId: string;
  providerStaffId: string;
  date: string;
  checkedInBy: string | null;
}

export type CheckinResult =
  | { ok: true; appointment: AppointmentRow }
  | { ok: false; reason: 'NOT_BOOKED' | 'ERROR' };

/** GoTrue/app appointment ids are UUIDs (the primary key of appointments). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The appointment id is the last non-empty URL segment (route parity). */
export function appointmentIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

export async function handleAppointmentsCheckin(req: Request, deps: CheckinDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization (defense-in-depth — RLS stays the final
  // boundary). Facility-scoped capability, Laravel gate parity.
  if (!can(context, 'appointment:checkin')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  // Strict contract: check-in takes NO request body. Any JSON body with
  // keys is rejected — the client has nothing authoritative to send.
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
  // arbiter). Only 'booked' may be checked in.
  if (appointment.status !== 'booked') {
    return errorEnvelope(
      ErrorCodes.CONFLICT,
      `Only a booked appointment can be checked in (current status: ${appointment.status}).`,
      409,
      correlationId,
    );
  }

  // Atomic check-in: row-locked token mint + guarded status transition in
  // ONE transaction. NOT_BOOKED here means the guarded update matched zero
  // rows — a concurrent duplicate check-in already moved the appointment
  // (the exact same 409 the sequential duplicate would get from the status
  // gate above).
  const result = deps.checkInAppointment({
    tenantId: context.organizationId ?? '',
    facilityId: appointment.facilityId,
    appointmentId,
    providerStaffId: appointment.providerStaffId,
    date: appointment.startsAt.slice(0, 10),
    checkedInBy: user.id,
  });

  if (!result.ok) {
    if (result.reason === 'NOT_BOOKED') {
      return errorEnvelope(
        ErrorCodes.CONFLICT,
        `Only a booked appointment can be checked in (current status: ${appointment.status}).`,
        409,
        correlationId,
      );
    }
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. Facts only — no PHI.
  await deps.recordAudit({
    action: 'appointment.checked_in',
    resourceType: 'appointment',
    resourceId: result.appointment.id,
    actorId: user.id,
    tenantId: context.organizationId ?? '',
    facilityId: appointment.facilityId,
    correlationId,
    payload: {
      patientId: appointment.patientId,
      tokenNo: result.appointment.tokenNo,
      providerStaffId: appointment.providerStaffId,
    },
  });

  // Presentation refs (the Laravel present() embeds patient + provider).
  // Both are guaranteed in-scope because the appointment itself was.
  const patient = deps.findPatientByScope(claims, appointment.patientId);
  const provider = deps.findProviderByScope(claims, appointment.providerStaffId);

  return successEnvelope(
    presentAppointment(result.appointment, patient, provider),
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
