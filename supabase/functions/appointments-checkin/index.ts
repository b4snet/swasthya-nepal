/**
 * appointments-checkin — the SECOND write domain Edge Function (Phase 10).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/appointments_checkin.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * Production-critical wiring preserved from the Laravel source of truth
 * (AppointmentController::checkIn + TokenIssuer):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the appointment lookup and the check-in mutation run as swasthya_app —
 *      RLS is the final boundary (both appointments and token_counters are
 *      claims-scoped tables);
 *   3. the ATOMIC check-in transaction mirrors TokenIssuer: the counter row
 *      keyed by (tenant, facility, provider, date) is locked FOR UPDATE, the
 *      next token is minted, and the appointment status transition is GUARDED
 *      (`WHERE id = … AND status = 'booked'`) — two concurrent check-ins of
 *      one appointment yield exactly one success, and parallel check-ins of
 *      the same provider/day can never receive the same token. A zero-row
 *      guarded update rolls the whole transaction back (no token wasted);
 *   4. audit appends mirror AuditLogger exactly (advisory lock + prev_hash +
 *      sha256 chain), attributed to the actor + authoritative context.
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleAppointmentsCheckin } from '../_shared/appointments_checkin.ts';
import type {
  AppointmentRow,
  AuditEventInput,
  CheckinDeps,
  CheckinInput,
  CheckinResult,
  PatientRef,
  ProviderRef,
} from '../_shared/appointments_checkin.ts';
import type { Claims } from '../_shared/claims.ts';
import type { HealthAuthDeps } from '../_shared/pipeline.ts';

const db = postgresFromEnv(); // deployed wiring — see supabase/README.md

const identityDeps: HealthAuthDeps = {
  secret: Deno.env.get('SUPABASE_JWT_SECRET') ?? '',
  issuer: 'supabase',
  audience: 'authenticated',
  findUserBySubject: (sub) => {
    const row = db.queryObject<{ id: string; email: string | null; status: string }>(
      'select id, email, status from public.users where auth_subject_id = $1 limit 1',
      [sub],
    ).rows[0];
    return row ? { id: row.id, email: row.email ?? undefined, status: row.status as AppUserStatus } : null;
  },
  loadActiveAssignments: (userId) =>
    db.queryObject<AssignmentRow>(
      `select ra.id, ra.user_id, ra.role_id, ra.tenant_id, ra.facility_id, ra.branch_id, ra.scope_type,
              r.code as role_code, r.scope_type as role_scope_type
         from public.role_assignments ra
         join public.roles r on r.id = ra.role_id
        where ra.user_id = $1 and ra.status = 'active'`,
      [userId],
    ).rows.map((row) => ({
      id: row.id,
      userId: row.user_id,
      roleId: row.role_id,
      tenantId: row.tenant_id,
      facilityId: row.facility_id,
      branchId: row.branch_id,
      scopeType: row.scope_type,
      role: { id: row.role_id, code: row.role_code, scopeType: row.role_scope_type, permissions: [] },
    })),
  activeSupportSession: (userId) => {
    const row = db.queryObject<{ id: string; organization_id: string; facility_id: string | null }>(
      `select id, organization_id, facility_id from public.support_sessions
        where user_id = $1 and status = 'active' and expires_at > now()
        order by opened_at desc limit 1`,
      [userId],
    ).rows[0];
    return row ? { id: row.id, organizationId: row.organization_id, facilityId: row.facility_id } : null;
  },
  loadOrganization: (id) => {
    const row = db.queryObject<{ id: string; status: string; timezone: string | null }>(
      'select id, status, timezone from public.organizations where id = $1',
      [id],
    ).rows[0];
    return row ? { id: row.id, status: row.status, timezone: row.timezone ?? undefined } : null;
  },
  loadFacility: (id) => {
    const row = db.queryObject<{ id: string; tenant_id: string; timezone: string | null }>(
      'select id, tenant_id, timezone from public.facilities where id = $1',
      [id],
    ).rows[0];
    return row ? { id: row.id, tenantId: row.tenant_id, timezone: row.timezone ?? undefined } : null;
  },
  loadBranch: (id) => {
    const row = db.queryObject<{ id: string; tenant_id: string; facility_id: string }>(
      'select id, tenant_id, facility_id from public.branches where id = $1',
      [id],
    ).rows[0];
    return row ? { id: row.id, tenantId: row.tenant_id, facilityId: row.facility_id } : null;
  },
};

const deps: CheckinDeps = {
  ...identityDeps,

  findAppointmentByScope: (claims: Claims, id: string): AppointmentRow | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<AppointmentDbRow>(
      `select id, facility_id, patient_id, provider_staff_id, service_id,
              appointment_type, starts_at::text, ends_at::text, status, token_no,
              source, cancel_reason, lock_version, created_at::text, updated_at::text
         from public.appointments
        where id = $3 and tenant_id = $1 and facility_id = $2 limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    return row ? mapAppointment(row) : null;
  },

  findPatientByScope: (claims: Claims, patientId: string): PatientRef | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<{ id: string; mrn: string; full_name: string }>(
      `select id, mrn, full_name from public.patients
        where id = $3 and tenant_id = $1 and facility_id = $2 limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, patientId],
    ).rows[0];
    return row ? { id: row.id, mrn: row.mrn, fullName: row.full_name } : null;
  },

  findProviderByScope: (claims: Claims, providerStaffId: string): ProviderRef | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<{ id: string; facility_id: string; full_name: string }>(
      `select id, facility_id, full_name from public.staff
        where id = $3 and tenant_id = $1 and facility_id = $2 and status <> 'departed' limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, providerStaffId],
    ).rows[0];
    return row ? { id: row.id, facilityId: row.facility_id, fullName: row.full_name } : null;
  },

  checkInAppointment: (input: CheckinInput): CheckinResult => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claimsFor(input))]);
    const tx = db.transaction();
    try {
      // TokenIssuer parity: the counter row (one per tenant+facility+provider+
      // date — uq_token_counters_tenant_facility_provider_date) is created
      // idempotently and locked FOR UPDATE; the row lock serializes parallel
      // issuers, so no two check-ins can mint the same token. token_counters
      // is a claims-scoped table: the INSERT/UPDATE only succeed under the
      // authoritative tenant/facility claims.
      tx.execute(
        `insert into public.token_counters (id, tenant_id, facility_id, provider_staff_id, queue_date, last_token)
         values (gen_random_uuid(), $1, $2, $3, $4::date, 0)
         on conflict on constraint uq_token_counters_tenant_facility_provider_date do nothing`,
        [input.tenantId, input.facilityId, input.providerStaffId, input.date],
      );
      const counter = tx.queryObject<{ id: string; last_token: number }>(
        `select id, last_token from public.token_counters
          where tenant_id = $1 and facility_id = $2 and provider_staff_id = $3 and queue_date = $4::date
          for update`,
        [input.tenantId, input.facilityId, input.providerStaffId, input.date],
      ).rows[0];
      const token = (counter?.last_token ?? 0) + 1;
      tx.execute(
        'update public.token_counters set last_token = $1, updated_at = now() where id = $2',
        [token, counter?.id ?? ''],
      );

      // GUARDED status transition — the DB decides eligibility atomically.
      // A zero-row update (already checked in / cancelled / completed by a
      // concurrent request) rolls the WHOLE transaction back: no token is
      // wasted and no partial mutation survives.
      const updated = tx.queryObject<AppointmentDbRow>(
        `update public.appointments
            set status = 'checked_in', token_no = $4, checked_in_by = $5,
                checked_in_at = now(), lock_version = lock_version + 1, updated_at = now()
          where id = $1 and tenant_id = $2 and facility_id = $3 and status = 'booked'
          returning id, facility_id, patient_id, provider_staff_id, service_id,
                    appointment_type, starts_at::text, ends_at::text, status, token_no,
                    source, cancel_reason, lock_version, created_at::text, updated_at::text`,
        [input.appointmentId, input.tenantId, input.facilityId, token, input.checkedInBy],
      ).rows[0];

      if (updated === undefined) {
        tx.rollback();
        return { ok: false, reason: 'NOT_BOOKED' };
      }
      tx.commit();
      return { ok: true, appointment: mapAppointment(updated) };
    } catch (error) {
      tx.rollback();
      if (isUniqueViolation(error)) {
        // The counter insert race (same provider+date from two transactions
        // where the ON CONFLICT path is not taken) — the lock path is the
        // normal serialization; this is a fail-closed backstop.
        return { ok: false, reason: 'ERROR' };
      }
      return { ok: false, reason: 'ERROR' };
    }
  },

  recordAudit: async (event: AuditEventInput): Promise<void> => {
    // Mirror of AuditLogger.record — see appointments-create/index.ts.
    const tx = db.transaction();
    try {
      tx.execute('select pg_advisory_xact_lock($1)', [CHAIN_LOCK_KEY]);
      const prev = tx.queryObject<{ event_hash: string | null }>(
        'select event_hash from public.audit_events order by occurred_at desc, id desc limit 1',
      ).rows[0];
      const prevHash = prev?.event_hash ?? null;
      const id = crypto.randomUUID();
      const occurredAt = new Date().toISOString();
      const chainPayload = [
        id, event.tenantId, occurredAt, 'user', event.actorId ?? '', '', event.action,
        event.resourceType, event.resourceId, event.facilityId, canonicalJson(event.payload), '',
        event.correlationId,
      ].join('|');
      const eventHash = await sha256Hex(`${prevHash ?? ''}|${chainPayload}`);
      tx.queryObject(
        `insert into public.audit_events
           (id, tenant_id, occurred_at, actor_type, actor_id, action, resource_type,
            resource_id, facility_id, payload, correlation_id, prev_hash, event_hash)
         values ($1, $2, $3::timestamptz, 'user', $4, $5, $6, $7, $8, $9::jsonb, $10, $11, $12)`,
        [id, event.tenantId === '' ? null : event.tenantId, occurredAt, event.actorId,
         event.action, event.resourceType, event.resourceId, event.facilityId === '' ? null : event.facilityId,
         JSON.stringify(event.payload), event.correlationId, prevHash, eventHash],
      );
      tx.commit();
    } catch {
      tx.rollback();
      throw new Error('audit append failed');
    }
  },
};

Deno.serve((req) => handleAppointmentsCheckin(req, deps));

/* ------------------------------------------------------------------ */
/* Deployed-only wiring helpers (types + driver bootstrap)             */
/* ------------------------------------------------------------------ */

type AppUserStatus = 'pending' | 'active' | 'locked' | 'disabled';

interface AssignmentRow {
  id: string;
  user_id: string;
  role_id: string;
  tenant_id: string | null;
  facility_id: string | null;
  branch_id: string | null;
  scope_type: string;
  role_code: string;
  role_scope_type: 'platform' | 'organization' | 'facility';
}

interface AppointmentDbRow {
  id: string;
  facility_id: string;
  patient_id: string;
  provider_staff_id: string;
  service_id: string | null;
  appointment_type: string;
  starts_at: string;
  ends_at: string;
  status: string;
  token_no: number | null;
  source: string;
  cancel_reason: string | null;
  lock_version: number;
  created_at: string | null;
  updated_at: string | null;
}

function mapAppointment(row: AppointmentDbRow): AppointmentRow {
  return {
    id: row.id,
    facilityId: row.facility_id,
    patientId: row.patient_id,
    providerStaffId: row.provider_staff_id,
    serviceId: row.service_id,
    appointmentType: row.appointment_type,
    startsAt: row.starts_at,
    endsAt: row.ends_at,
    status: row.status,
    tokenNo: row.token_no,
    source: row.source,
    cancelReason: row.cancel_reason,
    lockVersion: row.lock_version,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

/** The server-derived claims for the mutation GUC (from the pipeline result). */
function claimsFor(input: CheckinInput): Claims {
  return {
    app_user_id: input.checkedInBy ?? '',
    app_tenant_id: input.tenantId,
    app_facility_id: input.facilityId,
    app_branch_id: '',
    app_is_platform: 'false',
  };
}

function isUniqueViolation(error: unknown): boolean {
  const code = (error as { code?: string })?.code;
  return code === '23505';
}

const CHAIN_LOCK_KEY = 41_090_701; // crc32('swasthya.audit_events') & 0x7fffffff — AuditLogger parity

/** Stable stringification (AuditEvent::canonicalJson parity: sorted keys). */
function canonicalJson(value: unknown): string {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return JSON.stringify(value);
  }
  const out: Record<string, unknown> = {};
  for (const key of Object.keys(value as Record<string, unknown>).sort()) {
    out[key] = (value as Record<string, unknown>)[key];
  }
  return JSON.stringify(out);
}

/** sha256 hex via Web Crypto (Deno) — the AuditLogger chain hash. */
async function sha256Hex(input: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
  return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
  transaction(): {
    execute(sql: string, params?: unknown[]): void;
    queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
    commit(): void;
    rollback(): void;
  };
} {
  throw new Error(
    'appointments-checkin wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
