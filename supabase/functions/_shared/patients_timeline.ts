/**
 * The `patients:timeline` domain function (pure request handler, Phase 26) —
 * the patient-scoped timeline read, mirroring the established Laravel
 * contract exactly (PatientController::timeline — the
 * `patients/{patient}/timeline` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'patient:view')`
 *     capability (mirror of the Laravel route gate
 *     `authorize:patient:view` — the same gate as `patients:show`);
 *  3. the patient id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the patient lookup is claims-scoped (patients is TENANT_FACILITY)
 *     and decides 404 semantics; the timeline entries are read under the
 *     same claims (patient_timeline_entries is TENANT_ONLY) bound to the
 *     verified patient id and ordered by occurred_at DESC, then id DESC —
 *     the exact `->orderByDesc('occurred_at')->orderByDesc('id')`;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): a patient that does not exist AND a
 *     patient that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved timeline fields — the exact
 *     PatientController::timeline map: {id, occurredAt, eventType,
 *     summary}. `occurredAt` is nullable ISO (`occurred_at?->toIso8601String()`);
 *     `summary` is the decoded jsonb (the PatientTimelineEntry cast is
 *     'array' — the response carries the structured payload, never
 *     clinical content per the no-PHI timeline rule). NO related data, NO
 *     actor/correlation fields;
 *  7. NO audit — PatientController::timeline records no audit event;
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No pagination (bare `->get()` array). No invented fields.
 * No RLS weakening. No SECURITY DEFINER. No service-role credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { patientIdFromUrl } from './patients_show.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented timeline entry (the exact PatientController::timeline map). */
export interface TimelineEntryRow {
  id: string;
  occurredAt: string | null;
  eventType: string;
  summary: Record<string, unknown>;
}

export interface PatientsTimelineDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped timeline read (swasthya_app under the claims; the
   * patient id is a resource selector). Resolves the patient under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the patient's timeline entries
   * (ordered by occurred_at descending, then id descending — the exact
   * `->orderByDesc('occurred_at')->orderByDesc('id')`; `occurredAt`
   * nullable; `summary` the decoded jsonb). No mutation.
   */
  listPatientTimeline: (claims: Claims, id: string) => TimelineEntryRow[] | null;
}

export async function handlePatientsTimeline(req: Request, deps: PatientsTimelineDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:patient:view`.
  if (!can(context, 'patient:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const patientId = patientIdFromUrl(req);

  // A missing or malformed identifier is indistinguishable from a missing
  // resource — 404, never 400/422 (Laravel's implicit binding resolves to
  // the same ModelNotFoundException).
  if (patientId === '' || !isUuid(patientId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope patient resolves to null here
  // and produces the SAME 404 as a nonexistent one (AccessCheck::scoped,
  // reads). The id is a resource selector — never authorization scope.
  const entries = deps.listPatientTimeline(claims, patientId);

  if (entries === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact PatientController::timeline data shape: the bare entry list
  // (already ordered by occurred_at DESC / id DESC by the RLS-scoped read),
  // wrapped in the standard envelope. No audit — the Laravel read does not
  // audit.
  return successEnvelope(
    entries,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app patient ids are UUIDs (the primary key of public.patients). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
