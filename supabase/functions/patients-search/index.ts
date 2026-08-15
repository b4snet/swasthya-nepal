/**
 * patients-search — the candidate patient SEARCH Edge Function (Phase 23).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/patients_search.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped search is the production-critical wiring (PatientController
 * ::search + SearchPatientRequest parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the patients SELECT runs as swasthya_app — the claims-scoped
 *      patients policy is the FINAL boundary. The explicit scope WHERE
 *      mirrors the RLS facilityClause exactly via `coalesce($2,
 *      facility_id) = facility_id` (also gives PG a uuid type for the
 *      parameter): a facility claim narrows to one facility, an org-level
 *      claim (facility NULL) searches the whole tenant — Laravel's
 *      `if (facilityId() !== null)` parity;
 *   3. the match predicates reproduce Laravel exactly: `status = 'active'`,
 *      `lower(full_name) like '%q%' or lower(mrn) like 'q%'` (bindings
 *      lowercased in PHP, LIKE wildcards intentionally unescaped —
 *      Laravel parity), the score is pg_trgm
 *      `similarity(lower(full_name), q)` (the extension is created by the
 *      patients migration), ordered `score desc`, hard `limit 20`;
 *   4. audit appends mirror AuditLogger exactly (`patient.searched`,
 *      resourceType patient_search, resourceId null, payload
 *      {resultCount}) — recorded on EVERY search.
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handlePatientsSearch } from '../_shared/patients_search.ts';
import type { PatientsSearchDeps, PatientSearchRow } from '../_shared/patients_search.ts';
import type { AuditEventInput } from '../_shared/appointments_create.ts';
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

const deps: PatientsSearchDeps = {
  ...identityDeps,

  searchPatients: (claims: Claims, q: string): PatientSearchRow[] => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const lowerQ = q.toLowerCase();
    const rows = db.queryObject<PatientSearchDbRow>(
      `select id, mrn, full_name, date_of_birth, sex, facility_id,
              similarity(lower(full_name), $3) as score
         from public.patients
        where tenant_id = $1
          and coalesce($2, facility_id) = facility_id
          and status = 'active'
          and (lower(full_name) like $4 or lower(mrn) like $5)
        order by score desc
        limit 20`,
      [
        claims.app_tenant_id === '' ? null : claims.app_tenant_id,
        claims.app_facility_id === '' ? null : claims.app_facility_id,
        lowerQ,
        `%${lowerQ}%`,
        `${lowerQ}%`,
      ],
    ).rows;
    return rows.map((row) => ({
      id: row.id,
      mrn: row.mrn,
      fullName: row.full_name,
      dateOfBirth: row.date_of_birth,
      sex: row.sex,
      facilityId: row.facility_id,
      // Laravel: round((float) $patient->score, 4).
      score: Math.round(Number(row.score) * 10_000) / 10_000,
    }));
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
        event.resourceType, event.resourceId ?? '', event.facilityId, canonicalJson(event.payload), '',
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

Deno.serve((req) => handlePatientsSearch(req, deps));

/* ------------------------------------------------------------------ */

interface PatientSearchDbRow {
  id: string;
  mrn: string;
  full_name: string;
  date_of_birth: string;
  sex: string;
  facility_id: string;
  score: number | string;
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
    'patients-search wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
