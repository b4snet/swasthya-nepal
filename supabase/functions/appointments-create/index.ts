/**
 * appointments-create — the FIRST write domain Edge Function (Phase 9).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/appointments_create.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * Production-critical wiring preserved from the Laravel source of truth:
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. resource resolution (patient/provider) and the INSERT run as
 *      swasthya_app — RLS is the final boundary; the explicit scope WHERE
 *      is defense-in-depth only;
 *   3. the INSERT runs inside a transaction, and the partial unique index
 *      uq_appointments_tenant_provider_start (one LIVE booking per
 *      tenant+provider+start) is the FINAL double-booking arbiter: two
 *      concurrent requests racing on the same slot cannot both succeed —
 *      the second surfaces as a unique violation → 409 SLOT_TAKEN;
 *   4. audit appends mirror AuditLogger exactly: transaction-scoped
 *      advisory lock, prev_hash lookup, sha256 chain over the canonical
 *      chainPayload, actor + authoritative tenant/facility + correlation.
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleAppointmentsCreate } from '../_shared/appointments_create.ts';
import type {
  AppointmentsCreateDeps,
  AppointmentInsertInput,
  AppointmentRow,
  AuditEventInput,
  CreateResult,
  PatientRef,
  ProviderRef,
  ScheduleData,
} from '../_shared/appointments_create.ts';
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

const deps: AppointmentsCreateDeps = {
  ...identityDeps,

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

  loadSchedule: (claims: Claims, providerStaffId: string, date: string): ScheduleData => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    const exception = db.queryObject<{ id: string }>(
      `select id from public.schedule_exceptions
        where tenant_id = $1 and facility_id = $2 and staff_id = $3
          and exception_date = $4::date and status = 'active' limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, providerStaffId, date],
    ).rows[0];

    const templates = db.queryObject<TemplateRow>(
      `select starts_at::text as starts_at, ends_at::text as ends_at, slot_minutes, capacity
         from public.schedule_templates
        where tenant_id = $1 and facility_id = $2 and staff_id = $3
          and status = 'active' and deleted_at is null
          and day_of_week = extract(isodow from $4::date)::int
          and valid_from <= $4::date and (valid_to is null or valid_to >= $4::date)`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, providerStaffId, date],
    ).rows;

    const holdings = db.queryObject<HoldingRow>(
      `select starts_at::text as starts_at, count(*)::int as taken
         from public.appointments
        where tenant_id = $1 and provider_staff_id = $2
          and status in ('booked', 'checked_in', 'in_consultation')
          and starts_at >= $3::timestamptz and starts_at < ($3::date + 1)::timestamptz
        group by starts_at`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, providerStaffId, date],
    ).rows;

    return {
      exceptionActive: exception !== undefined,
      templates: templates.map((t) => ({
        startsAt: t.starts_at,
        endsAt: t.ends_at,
        slotMinutes: t.slot_minutes,
        capacity: t.capacity,
      })),
      holdings: holdings.map((h) => ({ startsAt: h.starts_at, taken: h.taken })),
    };
  },

  createAppointment: (input: AppointmentInsertInput): CreateResult => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claimsFor(input))]);
    const tx = db.transaction();
    try {
      // The INSERT runs as swasthya_app inside the transaction. The partial
      // unique index uq_appointments_tenant_provider_start is the FINAL
      // arbiter: a concurrent booking of the same live slot violates it and
      // aborts this transaction → SLOT_TAKEN. No JS availability check can
      // ever substitute for this (TOCTOU-safe by construction).
      const row = tx.queryObject<AppointmentDbRow>(
        `insert into public.appointments
           (id, tenant_id, facility_id, patient_id, provider_staff_id, service_id,
            appointment_type, starts_at, ends_at, status, source, lock_version, created_by)
         values
           (gen_random_uuid(), $1, $2, $3, $4, $5, $6, $7::timestamptz, $8::timestamptz,
            'booked', $9, 0, $10)
         returning id, facility_id, patient_id, provider_staff_id, service_id,
                   appointment_type, starts_at::text, ends_at::text, status,
                   token_no, source, cancel_reason, lock_version,
                   created_at::text, updated_at::text`,
        [input.tenantId, input.facilityId, input.patientId, input.providerStaffId, input.serviceId,
         input.appointmentType, input.startsAt, input.endsAt, input.source, input.createdBy],
      ).rows[0];
      tx.commit();
      return { ok: true, appointment: mapAppointment(row) };
    } catch (error) {
      tx.rollback();
      if (isUniqueViolation(error)) {
        return { ok: false, reason: 'SLOT_TAKEN' };
      }
      return { ok: false, reason: 'ERROR' };
    }
  },

  recordAudit: async (event: AuditEventInput): Promise<void> => {
    // Mirror of AuditLogger.record: transaction-scoped advisory lock keeps
    // the sha256 chain append-only and unforkable; every field is derived
    // server-side (actor from the authenticated identity, tenant/facility
    // from the authoritative context, correlation from the request).
    const tx = db.transaction();
    try {
      tx.execute('select pg_advisory_xact_lock($1)', [CHAIN_LOCK_KEY]);
      const prev = tx.queryObject<{ event_hash: string | null }>(
        `select event_hash from public.audit_events order by occurred_at desc, id desc limit 1`,
      ).rows[0];
      const prevHash = prev?.event_hash ?? null;
      const id = crypto.randomUUID();
      const occurredAt = new Date().toISOString();
      const chainPayload = [
        id,
        event.tenantId,
        occurredAt,
        'user',
        event.actorId ?? '',
        '',
        event.action,
        event.resourceType,
        event.resourceId,
        event.facilityId,
        canonicalJson(event.payload),
        '',
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
      // Audit failure must not silently lose the event: the transaction is
      // rolled back, and the caller surfaces a server error (fail closed).
      throw new Error('audit append failed');
    }
  },
};

Deno.serve((req) => handleAppointmentsCreate(req, deps));

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

interface TemplateRow {
  starts_at: string;
  ends_at: string;
  slot_minutes: number;
  capacity: number;
}

interface HoldingRow {
  starts_at: string;
  taken: number;
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

/** The server-derived claims for the insert GUC (from the pipeline result). */
function claimsFor(input: AppointmentInsertInput): Claims {
  return {
    app_user_id: input.createdBy ?? '',
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
    'appointments-create wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
