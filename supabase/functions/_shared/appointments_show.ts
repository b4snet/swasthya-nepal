/**
 * The `appointments:show` domain function (pure request handler, Phase 21) —
 * the single-appointment READ, mirroring the established Laravel contract
 * exactly (AppointmentController::show — the `appointments/{appointment}`
 * route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'appointment:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:appointment:view`);
 *  3. the appointment id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped single-row read runs as swasthya_app with
 *     request.jwt.claims set — the claims-scoped appointments policy decides
 *     visibility (appointments is TENANT_FACILITY);
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): an appointment that does not exist AND
 *     an appointment that exists but is outside the caller's scope BOTH
 *     return `404 NOT_FOUND` 'Resource not found.' — existence is never
 *     leaked;
 *  6. present ONLY the approved fields — the exact
 *     AppointmentController::present shape: id, facilityId, patientId,
 *     patient (ref), providerStaffId, provider (ref), serviceId,
 *     appointmentType, startsAt, endsAt, status, tokenNo, source,
 *     cancelReason, lockVersion. The Laravel show contract resolves the
 *     patient and provider relations for the refs; under RLS a related row
 *     outside the caller's scope renders null (established Phase 18 parity
 *     convention — `payment?->method`);
 *  7. NO audit — AppointmentController::show records no audit event
 *     (unlike encounters:show); a pure read with no mutation;
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No RLS weakening. No SECURITY DEFINER. No service-role
 * credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { appointmentIdFromUrl } from './appointments_checkin.ts';
import {
  presentAppointment,
  type AppointmentRow,
  type PatientRef,
  type ProviderRef,
} from './appointments_create.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

export interface AppointmentsShowDeps extends HealthAuthDeps {
  /** RLS-scoped appointment lookup (claims decide visibility; out-of-scope ≡ null). */
  findAppointmentByScope: (claims: Claims, id: string) => AppointmentRow | null;
  /** RLS-scoped patient lookup (for the presentation ref, like create/checkin). */
  findPatientByScope: (claims: Claims, patientId: string) => PatientRef | null;
  /** RLS-scoped provider (staff) lookup (for the presentation ref). */
  findProviderByScope: (claims: Claims, providerStaffId: string) => ProviderRef | null;
}

/** GoTrue/app appointment ids are UUIDs (the primary key of public.appointments). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

export async function handleAppointmentsShow(req: Request, deps: AppointmentsShowDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:appointment:view`.
  if (!can(context, 'appointment:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const appointmentId = appointmentIdFromUrl(req);

  // A missing or malformed identifier is indistinguishable from a missing
  // resource — 404, never 400/422 (Laravel's implicit binding resolves to
  // the same ModelNotFoundException).
  if (appointmentId === '' || !isUuid(appointmentId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope appointment resolves to null
  // here and produces the SAME 404 as a nonexistent one (AccessCheck::scoped,
  // reads). The id is a resource selector — never authorization scope.
  const appointment = deps.findAppointmentByScope(claims, appointmentId);

  if (appointment === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // Laravel present() parity: the patient/provider refs are resolved through
  // the same claims — an out-of-scope related row renders null (never an
  // error and never a scope leak).
  const patient = deps.findPatientByScope(claims, appointment.patientId);
  const provider = deps.findProviderByScope(claims, appointment.providerStaffId);

  // The exact AppointmentController::present data shape (shared with
  // appointments:create / appointments:checkin).
  return successEnvelope(
    presentAppointment(appointment, patient, provider),
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
