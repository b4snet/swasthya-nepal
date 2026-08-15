/**
 * The `appointments:index` domain function (pure request handler, Phase 22) —
 * the claims-scoped appointment LIST, mirroring the established Laravel
 * contract exactly (AppointmentController::index — the `GET appointments`
 * route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'appointment:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:appointment:view`);
 *  3. filters (AppointmentController::index): `date` (whereDate on
 *     starts_at) and `providerStaffId` (exact match), each applied only
 *     when present; absent parameters mean "no filter". Unknown parameters
 *     are IGNORED (the controller reads only these two — no validation
 *     exists);
 *  4. scope comes exclusively from the authoritative context/claims — the
 *     RLS-scoped `listAppointments` dependency returns only the rows the
 *     principal can see (appointments is TENANT_FACILITY: the tenant claim
 *     always applies, the facility claim applies when set — an org-level
 *     context sees every facility of the tenant, exactly like the RLS
 *     facilityClause `facility_id = claim OR claim IS NULL`);
 *  5. ordering: `orderBy('starts_at')` ascending — the exact Laravel order;
 *     NO pagination (the controller uses a plain `->get()` and returns a
 *     bare array of `present()` items);
 *  6. related data: the patient and provider refs resolve under the SAME
 *     claims (mirror of the eager-loaded `patient:id,mrn,full_name` and
 *     `provider:id,full_name` relations) — a related row outside the
 *     caller's scope renders `null` (established Phase 18/21 parity);
 *  7. NO audit — AppointmentController::index records no audit event;
 *  8. malformed filter parity: a PRESENT-but-malformed `date` /
 *     `providerStaffId` reaches Postgres in Laravel's whereDate()/where()
 *     and fails the column cast (invalid input syntax for type date/uuid)
 *     → an unhandled 500. The edge mirrors that observable 500
 *     deterministically (accepted `date` format: YYYY-MM-DD — a documented
 *     strict fail-closed subset of PG's lenient date input);
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
import {
  presentAppointment,
  type AppointmentRow,
  type PatientRef,
  type ProviderRef,
} from './appointments_create.ts';

/** A visible appointment plus its claims-resolved patient/provider refs
 * (mirror of the eager-loaded relations in AppointmentController::index). */
export interface AppointmentListItem {
  appointment: AppointmentRow;
  patient: PatientRef | null;
  provider: ProviderRef | null;
}

/** The ONLY filters the Laravel index accepts (absent = unfiltered). */
export interface AppointmentsIndexFilters {
  date?: string;
  providerStaffId?: string;
}

export interface AppointmentsIndexDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped appointment list (swasthya_app under the claims; mirror
   * of AppointmentController::index): tenant scope always, facility scope
   * only when a facility claim exists, the date/providerStaffId filters,
   * `orderBy starts_at`, with the patient/provider refs resolved under the
   * same claims. Out-of-scope rows and refs never appear.
   */
  listAppointments: (claims: Claims, filters: AppointmentsIndexFilters) => AppointmentListItem[];
}

/** GoTrue/app appointment ids are UUIDs (the primary key of public.appointments). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/**
 * The canonical `date` filter format (YYYY-MM-DD). PG's date input is
 * lenient; the edge accepts ONLY the canonical calendar form and fails
 * closed on everything else (documented strict subset of the Laravel/PG
 * behavior — a malformed value 500s in Laravel too, via the PG cast).
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

export async function handleAppointmentsIndex(req: Request, deps: AppointmentsIndexDeps): Promise<Response> {
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

  // The two Laravel-accepted filters; `get` returns null when absent, so
  // absent vs present-but-empty is distinguished exactly like
  // `$request->has(...)`. Unknown parameters are ignored (Laravel parity).
  const searchParams = new URL(req.url).searchParams;
  const date = searchParams.get('date');
  const providerStaffId = searchParams.get('providerStaffId');

  // Laravel parity: a present-but-malformed filter value 500s in Laravel
  // (the value reaches Postgres and fails the date/uuid column cast in
  // whereDate()/where()). The edge mirrors that observable 500
  // deterministically instead of returning an invented 4xx.
  if (date !== null && !isCalendarDate(date)) {
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }
  if (providerStaffId !== null && !isUuid(providerStaffId)) {
    return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
  }

  const filters: AppointmentsIndexFilters = {};
  if (date !== null) filters.date = date;
  if (providerStaffId !== null) filters.providerStaffId = providerStaffId;

  // RLS decides visibility and scope; the filters/order are applied inside
  // the claims-scoped read (mirror of the Laravel query).
  const items = deps.listAppointments(claims, filters);

  // The exact Laravel response: a bare array of present() items, ordered by
  // starts_at, patient/provider refs null when out of scope.
  return successEnvelope(
    items.map(({ appointment, patient, provider }) => presentAppointment(appointment, patient, provider)),
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
