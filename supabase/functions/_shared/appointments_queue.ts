/**
 * The `appointments:queue` domain function (pure request handler, Phase 27) —
 * the live front-desk queue read, mirroring the established Laravel contract
 * exactly (AppointmentController::queue — the `GET appointments/queue`
 * route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'queue:view')`
 *     capability (mirror of the Laravel route gate `authorize:queue:view`);
 *  3. filters (AppointmentController::queue): `date` (whereDate on
 *     starts_at, DEFAULTING to today when absent — the exact
 *     `$request->query('date', today()->toDateString())`, the default
 *     resolved server-side via the injected `todayIso` dependency) and
 *     `providerStaffId` (exact match, applied only when present); the
 *     status filter `checked_in / in_consultation` is ALWAYS applied
 *     (`whereIn` — the queue shows only live visits); unknown query
 *     parameters are IGNORED (no validation in the controller);
 *  4. scope comes exclusively from the authoritative context/claims — the
 *     RLS-scoped dependency returns only the rows the principal can see
 *     (appointments is TENANT_FACILITY: the tenant claim always applies,
 *     the facility claim applies when set — an org-level context sees every
 *     facility of the tenant, exactly like the RLS facilityClause
 *     `facility_id = claim OR claim IS NULL`);
 *  5. ordering: `orderBy('token_no')` ascending — the exact Laravel order;
 *     NO pagination (plain `->get()` → bare array);
 *  6. related data: the patient ref and the encounter id resolve under the
 *     SAME claims (mirror of the eager-loaded `patient:id,mrn,full_name,
 *     date_of_birth,sex` and `encounter:id,appointment_id` relations — only
 *     the presented patient fields leave the handler) — a related row
 *     outside the caller's scope renders `null` (established Phase 18/21
 *     parity);
 *  7. NO audit — AppointmentController::queue records no audit event;
 *  8. malformed filter parity: a PRESENT-but-malformed `date` /
 *     `providerStaffId` reaches Postgres in Laravel's whereDate()/where()
 *     and fails the column cast → an unhandled 500. The edge mirrors that
 *     observable 500 deterministically (accepted `date` format:
 *     YYYY-MM-DD — the documented strict fail-closed subset used by
 *     appointments:index);
 *  9. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No RLS weakening. No SECURITY DEFINER. No service-role
 * credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { PatientRef } from './appointments_create.ts';

/** One presented queue entry (the exact AppointmentController::queue map). */
export interface QueueEntryRow {
  appointmentId: string;
  tokenNo: number | null;
  status: string;
  patient: PatientRef | null;
  startsAt: string | null;
  encounterId: string | null;
}

/** The ONLY filters the Laravel queue accepts (`date` defaults to today). */
export interface AppointmentQueueFilters {
  date: string;
  providerStaffId?: string;
}

export interface AppointmentsQueueDeps extends HealthAuthDeps {
  /**
   * The server-side "today" (the exact `today()->toDateString()` default for
   * an absent `date` query parameter). Injected so the pure handler stays
   * deterministic; the deployed adapter resolves the runtime calendar date.
   */
  todayIso: () => string;
  /**
   * The RLS-scoped queue read (swasthya_app under the claims; mirror of
   * AppointmentController::queue): tenant scope always, facility scope only
   * when a facility claim exists, the always-applied checked_in /
   * in_consultation status filter, the date/providerStaffId filters,
   * `orderBy token_no` ascending, with the patient ref + encounter id
   * resolved under the same claims. Out-of-scope rows and refs never
   * appear. No mutation.
   */
  listAppointmentQueue: (claims: Claims, filters: AppointmentQueueFilters) => QueueEntryRow[];
}

/** GoTrue/app appointment ids are UUIDs (the primary key of public.appointments). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/**
 * The canonical `date` filter format (YYYY-MM-DD). PG's date input is
 * lenient; the edge accepts ONLY the canonical calendar form and fails
 * closed on everything else (the documented strict subset used by
 * appointments:index — a malformed value 500s in Laravel too, via the PG
 * cast).
 */
function isCalendarDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (match === null) return false;
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  if (month < 1 || month > 12) return false;
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
  return day >= 1 && day <= daysInMonth;
}

export async function handleAppointmentsQueue(req: Request, deps: AppointmentsQueueDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:queue:view`.
  if (!can(context, 'queue:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  // The queue filters: `date` defaults to the server-side today when absent
  // (`$request->query('date', today()->toDateString())`); `providerStaffId`
  // applies only when present. Unknown parameters are ignored (Laravel
  // parity).
  const searchParams = new URL(req.url).searchParams;
  const date = searchParams.get('date') ?? deps.todayIso();
  const providerStaffId = searchParams.get('providerStaffId');

  // Laravel parity: a present-but-malformed filter value 500s in Laravel
  // (the value reaches Postgres and fails the date/uuid column cast). The
  // edge mirrors that observable 500 deterministically.
  if (!isCalendarDate(date)) {
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }
  if (providerStaffId !== null && !isUuid(providerStaffId)) {
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }

  const filters: AppointmentQueueFilters = { date };
  if (providerStaffId !== null) filters.providerStaffId = providerStaffId;

  // RLS decides visibility and scope; the filters/order are applied inside
  // the claims-scoped read (mirror of the Laravel query).
  const entries = deps.listAppointmentQueue(claims, filters);

  // The exact Laravel response: a bare array of the queue entry map, ordered
  // by token_no, patient/encounter refs null when out of scope.
  return successEnvelope(
    entries,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
