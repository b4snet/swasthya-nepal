/**
 * The `appointments:create` domain function (pure request handler, Phase 9) —
 * the FIRST write endpoint on the shared pipeline.
 *
 * It is NOT a greenfield booking model: it mirrors the established Laravel
 * contract exactly (AppointmentController::store + BookAppointmentRequest +
 * SlotService + the uq_appointments_tenant_provider_start partial unique
 * index). The Laravel behavior is the source of truth; this function
 * executes it through the secure pipeline + the same RLS-scoped database.
 *
 * Flow:
 *  1. authenticate through the shared pipeline (JWT → sub → users → status
 *     gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'appointment:book')`
 *     capability (mirror of the Laravel route gate);
 *  3. validate the request (strict: unknown fields rejected, Laravel-style
 *     validation details);
 *  4. resolve the patient + provider through the RLS-scoped read path under
 *     the authoritative claims (not found OR out-of-scope → 404);
 *  5. derive availability from the schedule (templates − exceptions −
 *     holdings — the SlotService mirror) and reject unavailable slots;
 *  6. run the transactional INSERT: claims GUC set → INSERT (status
 *     'booked', lock_version 0, created_by = context user) → the partial
 *     unique index is the FINAL double-booking arbiter (a unique violation
 *     → 409 CONFLICT, never a JS check);
 *  7. audit 'appointment.booked' (actor + authoritative tenant/facility +
 *     correlation id);
 *  8. return the exact AppointmentController::present response shape.
 *
 * The client NEVER controls scope: tenant/facility/branch come exclusively
 * from the authoritative context/claims; forged app_* claims and forged
 * proposals are inert.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

export interface PatientRef {
  id: string;
  mrn: string;
  fullName: string;
}

export interface ProviderRef {
  id: string;
  fullName: string;
  facilityId: string;
}

/** A provider's schedule facts for one date (mirror of SlotService inputs). */
export interface ScheduleData {
  /** An active schedule exception for (provider, date) → no slots. */
  exceptionActive: boolean;
  /** Active templates covering the date: window + slot grid + capacity. */
  templates: { startsAt: string; endsAt: string; slotMinutes: number; capacity: number }[];
  /** Live bookings for the provider+date: ISO startsAt → count taken. */
  holdings: { startsAt: string; taken: number }[];
}

export interface AppointmentInsertInput {
  tenantId: string;
  facilityId: string;
  patientId: string;
  providerStaffId: string;
  serviceId: string | null;
  appointmentType: string;
  startsAt: string;
  endsAt: string;
  source: string;
  createdBy: string | null;
}

export interface AppointmentRow {
  id: string;
  facilityId: string;
  patientId: string;
  providerStaffId: string;
  serviceId: string | null;
  appointmentType: string;
  startsAt: string;
  endsAt: string;
  status: string;
  tokenNo: number | null;
  source: string;
  cancelReason: string | null;
  lockVersion: number;
  createdAt: string | null;
  updatedAt: string | null;
}

export type CreateResult =
  | { ok: true; appointment: AppointmentRow }
  | { ok: false; reason: 'SLOT_TAKEN' | 'ERROR' };

export interface AuditEventInput {
  action: string;
  resourceType: string;
  resourceId: string;
  actorId: string | null;
  tenantId: string;
  facilityId: string;
  correlationId: string;
  payload: Record<string, unknown>;
}

export interface AppointmentsCreateDeps extends HealthAuthDeps {
  /** RLS-scoped patient lookup (claims decide visibility; out-of-scope ≡ null). */
  findPatientByScope: (claims: Claims, patientId: string) => PatientRef | null;
  /** RLS-scoped provider (staff) lookup. */
  findProviderByScope: (claims: Claims, providerStaffId: string) => ProviderRef | null;
  /** Load the provider's schedule facts for a date (server-side). */
  loadSchedule: (claims: Claims, providerStaffId: string, date: string) => ScheduleData;
  /** The transactional INSERT with the unique-index race (deployed wiring). */
  createAppointment: (input: AppointmentInsertInput) => CreateResult;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

/* ------------------------------------------------------------------ */
/* Validation (mirror of BookAppointmentRequest + ApiExceptionMapper)  */
/* ------------------------------------------------------------------ */

export interface ValidationDetail {
  field: string;
  code: string;
  message: string;
}

const APPOINTMENT_TYPES = ['opd', 'follow_up', 'procedure', 'teleconsult'] as const;
const SOURCES = ['counter', 'portal', 'walk_in'] as const;
const ALLOWED_FIELDS = ['patientId', 'providerStaffId', 'serviceId', 'startsAt', 'endsAt', 'appointmentType', 'source'];

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function fieldLabel(field: string): string {
  // patientId → "patient id" (Laravel's humanized message style).
  return field.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase();
}

function detail(field: string, code: string, message: string): ValidationDetail {
  return { field, code, message };
}

function invalidFormat(field: string): ValidationDetail {
  return detail(field, 'INVALID_FORMAT', `The ${fieldLabel(field)} field must be a valid date.`);
}

function isValidIsoDate(value: string): boolean {
  const parsed = Date.parse(value);
  return Number.isFinite(parsed);
}

function isUuid(value: unknown): boolean {
  return typeof value === 'string' && UUID_RE.test(value);
}

/**
 * Validate the request body against the BookAppointmentRequest rules
 * (required / uuid / date / after / in) + strict unknown-field rejection.
 */
export function validateBookInput(body: Record<string, unknown>): { input: AppointmentInput; errors: ValidationDetail[] } {
  const errors: ValidationDetail[] = [];

  for (const key of Object.keys(body)) {
    if (!ALLOWED_FIELDS.includes(key)) {
      errors.push(detail(key, 'VALIDATION_ERROR', `Field "${key}" is not allowed.`));
    }
  }

  const required = ['patientId', 'providerStaffId', 'startsAt', 'endsAt'];
  for (const field of required) {
    if (body[field] === undefined || body[field] === null || body[field] === '') {
      errors.push(detail(field, 'REQUIRED', `The ${fieldLabel(field)} field is required.`));
    }
  }

  if (body.patientId !== undefined && !isUuid(body.patientId)) {
    errors.push(detail('patientId', 'INVALID_FORMAT', `The patient id field must be a valid UUID.`));
  }
  if (body.providerStaffId !== undefined && !isUuid(body.providerStaffId)) {
    errors.push(detail('providerStaffId', 'INVALID_FORMAT', `The provider staff id field must be a valid UUID.`));
  }
  if (body.serviceId !== undefined && body.serviceId !== null && !isUuid(body.serviceId)) {
    errors.push(detail('serviceId', 'INVALID_FORMAT', `The service id field must be a valid UUID.`));
  }

  const startsAt = body.startsAt;
  const endsAt = body.endsAt;
  if (startsAt !== undefined && startsAt !== null && (typeof startsAt !== 'string' || !isValidIsoDate(startsAt))) {
    errors.push(invalidFormat('startsAt'));
  }
  if (endsAt !== undefined && endsAt !== null && (typeof endsAt !== 'string' || !isValidIsoDate(endsAt))) {
    errors.push(invalidFormat('endsAt'));
  }
  if (
    typeof startsAt === 'string' && typeof endsAt === 'string'
    && isValidIsoDate(startsAt) && isValidIsoDate(endsAt)
    && Date.parse(endsAt) <= Date.parse(startsAt)
  ) {
    errors.push(detail('endsAt', 'VALIDATION_ERROR', `The ends at field must be a date after starts at.`));
  }

  if (body.appointmentType !== undefined && body.appointmentType !== null && !(APPOINTMENT_TYPES as readonly string[]).includes(body.appointmentType as string)) {
    errors.push(detail('appointmentType', 'NOT_ALLOWED', `The selected appointment type is invalid.`));
  }
  if (body.source !== undefined && body.source !== null && !(SOURCES as readonly string[]).includes(body.source as string)) {
    errors.push(detail('source', 'NOT_ALLOWED', `The selected source is invalid.`));
  }

  return {
    errors,
    input: {
      patientId: String(body.patientId ?? ''),
      providerStaffId: String(body.providerStaffId ?? ''),
      serviceId: body.serviceId === undefined || body.serviceId === null ? null : String(body.serviceId),
      startsAt: String(body.startsAt ?? ''),
      endsAt: String(body.endsAt ?? ''),
      appointmentType: String(body.appointmentType ?? 'opd'),
      source: String(body.source ?? 'counter'),
    },
  };
}

export interface AppointmentInput {
  patientId: string;
  providerStaffId: string;
  serviceId: string | null;
  startsAt: string;
  endsAt: string;
  appointmentType: string;
  source: string;
}

/* ------------------------------------------------------------------ */
/* Availability derivation (mirror of SlotService)                     */
/* ------------------------------------------------------------------ */

function timeToMinutes(time: string): number {
  const [h, m, s] = time.split(':').map((part) => Number.parseInt(part, 10) || 0);
  return h * 60 + m + (s > 0 ? s / 60 : 0);
}

/**
 * Whether the requested slot is open: no active exception, the start is on
 * the template grid within its window, and booked < capacity.
 */
export function slotAvailable(schedule: ScheduleData, startsAtIso: string): boolean {
  if (schedule.exceptionActive) return false;

  const slotStart = Date.parse(startsAtIso);
  if (!Number.isFinite(slotStart)) return false;

  const taken = schedule.holdings.find((h) => h.startsAt === startsAtIso)?.taken ?? 0;

  for (const template of schedule.templates) {
    const windowStart = Date.parse(`1970-01-01T${template.startsAt}Z`);
    const windowEnd = Date.parse(`1970-01-01T${template.endsAt}Z`);
    const slotMinutes = template.slotMinutes > 0 ? template.slotMinutes : 0;
    const dayStart = new Date(slotStart);
    dayStart.setUTCHours(0, 0, 0, 0);
    const minutesSinceDayStart = (slotStart - dayStart.getTime()) / 60000;
    const minutesSinceWindowStart = minutesSinceDayStart - timeToMinutes(template.startsAt);

    if (slotMinutes === 0) continue;
    const onGrid = minutesSinceWindowStart >= 0 && minutesSinceWindowStart % slotMinutes === 0;
    if (!onGrid) continue;

    const slotEndMinutes = minutesSinceDayStart + slotMinutes;
    const windowEndMinutes = timeToMinutes(template.endsAt);
    const fits = slotEndMinutes <= windowEndMinutes && slotEndMinutes > timeToMinutes(template.startsAt);
    void windowStart;
    void windowEnd;
    if (!fits) continue;

    return taken < template.capacity;
  }

  return false;
}

/* ------------------------------------------------------------------ */
/* Response presentation (exact AppointmentController::present shape)  */
/* ------------------------------------------------------------------ */

export function presentAppointment(
  appointment: AppointmentRow,
  patient: PatientRef | null,
  provider: ProviderRef | null,
): Record<string, unknown> {
  return {
    id: appointment.id,
    facilityId: appointment.facilityId,
    patientId: appointment.patientId,
    patient: patient ? { id: patient.id, mrn: patient.mrn, fullName: patient.fullName } : null,
    providerStaffId: appointment.providerStaffId,
    provider: provider ? { id: provider.id, fullName: provider.fullName } : null,
    serviceId: appointment.serviceId,
    appointmentType: appointment.appointmentType,
    startsAt: appointment.startsAt,
    endsAt: appointment.endsAt,
    status: appointment.status,
    tokenNo: appointment.tokenNo,
    source: appointment.source,
    cancelReason: appointment.cancelReason,
    lockVersion: appointment.lockVersion,
  };
}

/* ------------------------------------------------------------------ */
/* Handler                                                             */
/* ------------------------------------------------------------------ */

export async function handleAppointmentsCreate(req: Request, deps: AppointmentsCreateDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization (defense-in-depth — RLS stays the final
  // boundary). Tenant-scoped capability, Laravel gate parity.
  if (!can(context, 'appointment:book')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  let body: unknown;
  try {
    body = JSON.parse(await req.text());
  } catch {
    return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
  }
  if (typeof body !== 'object' || body === null || Array.isArray(body)) {
    return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
  }

  const { input, errors } = validateBookInput(body as Record<string, unknown>);

  if (errors.length > 0) {
    return errorEnvelope(
      ErrorCodes.VALIDATION_ERROR,
      `${errors.length} field(s) failed validation.`,
      422,
      correlationId,
      errors,
    );
  }

  // RLS-scoped resource resolution: not found AND out-of-scope both 404
  // (the runtime RLS behavior — existence is never leaked).
  const patient = deps.findPatientByScope(claims, input.patientId);
  if (patient === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Patient not found.', 404, correlationId);
  }

  const provider = deps.findProviderByScope(claims, input.providerStaffId);
  if (provider === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Staff record not found.', 404, correlationId);
  }

  // Laravel parity: facility = context facility, else the provider's.
  const facilityId = context.facilityId ?? provider.facilityId;
  const date = input.startsAt.slice(0, 10);

  const schedule = deps.loadSchedule(claims, provider.id, date);
  if (!slotAvailable(schedule, input.startsAt)) {
    return errorEnvelope(
      ErrorCodes.CONFLICT,
      'This slot is not available for booking — choose an open slot from availability.',
      409,
      correlationId,
    );
  }

  // The transactional INSERT. The DB (partial unique index) is the FINAL
  // double-booking arbiter — a race surfaces here as SLOT_TAKEN.
  const result = deps.createAppointment({
    tenantId: context.organizationId ?? '',
    facilityId,
    patientId: patient.id,
    providerStaffId: provider.id,
    serviceId: input.serviceId,
    appointmentType: input.appointmentType,
    startsAt: input.startsAt,
    endsAt: input.endsAt,
    source: input.source,
    createdBy: user.id,
  });

  if (!result.ok) {
    if (result.reason === 'SLOT_TAKEN') {
      return errorEnvelope(
        ErrorCodes.CONFLICT,
        'This slot was just booked by someone else — choose another slot.',
        409,
        correlationId,
      );
    }
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the request correlation id. Facts only,
  // no PHI (mirror of the Laravel appointment.booked event).
  await deps.recordAudit({
    action: 'appointment.booked',
    resourceType: 'appointment',
    resourceId: result.appointment.id,
    actorId: user.id,
    tenantId: context.organizationId ?? '',
    facilityId,
    correlationId,
    payload: { patientId: patient.id, providerStaffId: provider.id, startsAt: input.startsAt },
  });

  return successEnvelope(
    presentAppointment(result.appointment, patient, provider),
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
    201,
  );
}
