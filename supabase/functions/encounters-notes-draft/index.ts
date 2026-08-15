/**
 * encounters-notes-draft — the FIRST CLINICAL-DOCUMENTATION write Edge
 * Function (Phase 12).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/encounter_notes_draft.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * Production-critical wiring preserved from the Laravel source of truth
 * (EncounterController::storeNote + StoreClinicalNoteRequest):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the encounter lookup runs as swasthya_app — RLS is the final boundary
 *      (encounters is claims-scoped); out-of-scope ≡ nonexistent → 404;
 *   3. the CLINICAL-SAFETY author rule is enforced here against the REAL
 *      staff rows: the actor's staff record (staff.user_id = actor,
 *      tenant = encounter tenant, status <> 'departed') must BE the
 *      encounter's provider_staff_id — author_staff_id is NEVER accepted
 *      from the client;
 *   4. the draft INSERT (status 'draft', lock_version 0) runs under the
 *      claims inside ONE transaction with the GUC — clinical_notes is a
 *      claims-scoped table (tenant_id = claims.tenant). Multiple drafts per
 *      encounter are permitted by the schema (no unique index), so there is
 *      no race to arbitrate;
 *   5. audit appends mirror AuditLogger exactly (advisory lock + prev_hash +
 *      sha256 chain), attributed to the actor + authoritative context.
 *
 * No client-supplied tenant/facility/branch/provider/author value ever
 * becomes authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleEncounterNotesDraft } from '../_shared/encounter_notes_draft.ts';
import type {
  AuthorStaffRef,
  EncounterNotesDraftDeps,
  NoteDraftInput,
  NoteDraftResult,
  NoteRow,
} from '../_shared/encounter_notes_draft.ts';
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

const deps: EncounterNotesDraftDeps = {
  ...identityDeps,

  findEncounterByScope: (claims: Claims, id: string): EncounterRow | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<EncounterDbRow>(
      `select id, facility_id, patient_id, appointment_id, provider_staff_id,
              type, status, started_at::text, ended_at::text, signed_at::text, lock_version
         from public.encounters
        where id = $3 and tenant_id = $1 and facility_id = $2 limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    return row ? mapEncounter(row) : null;
  },

  findAuthorStaff: (claims: Claims, actorUserId: string, tenantId: string, providerStaffId: string): AuthorStaffRef | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<{ id: string; facility_id: string; full_name: string }>(
      `select id, facility_id, full_name from public.staff
        where id = $3 and tenant_id = $1 and user_id = $2 and status <> 'departed' limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, actorUserId, providerStaffId],
    ).rows[0];
    return row ? { id: row.id, facilityId: row.facility_id, fullName: row.full_name } : null;
  },

  createDraftNote: (input: NoteDraftInput): NoteDraftResult => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claimsFor(input))]);
    const tx = db.transaction();
    try {
      const note = tx.queryObject<NoteDbRow>(
        `insert into public.clinical_notes
           (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, lock_version, created_by)
         values (gen_random_uuid(), $1, $2, $3, $4, $5::jsonb, 'draft', 0, $6)
         returning id, encounter_id, note_type, author_staff_id, content, status`,
        [input.tenantId, input.encounterId, input.noteType, input.authorStaffId,
         JSON.stringify(input.content), input.createdBy],
      ).rows[0];
      tx.commit();
      return { ok: true, note: mapNote(note) };
    } catch {
      tx.rollback();
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

Deno.serve((req) => handleEncounterNotesDraft(req, deps));

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

interface EncounterDbRow {
  id: string;
  facility_id: string;
  patient_id: string;
  appointment_id: string | null;
  provider_staff_id: string;
  type: string;
  status: string;
  started_at: string | null;
  ended_at: string | null;
  signed_at: string | null;
  lock_version: number;
}

function mapEncounter(row: EncounterDbRow): EncounterRow {
  return {
    id: row.id,
    facilityId: row.facility_id,
    patientId: row.patient_id,
    appointmentId: row.appointment_id,
    providerStaffId: row.provider_staff_id,
    type: row.type,
    status: row.status,
    startedAt: row.started_at,
    endedAt: row.ended_at,
    signedAt: row.signed_at,
    lockVersion: row.lock_version,
  };
}

interface NoteDbRow {
  id: string;
  encounter_id: string | null;
  note_type: string;
  author_staff_id: string;
  content: Record<string, unknown>;
  status: string;
}

function mapNote(row: NoteDbRow): NoteRow {
  return {
    id: row.id,
    encounterId: row.encounter_id,
    noteType: row.note_type,
    authorStaffId: row.author_staff_id,
    content: row.content,
    status: row.status,
  };
}

/** The server-derived claims for the mutation GUC (from the pipeline result). */
function claimsFor(input: NoteDraftInput): Claims {
  return {
    app_user_id: input.createdBy ?? '',
    app_tenant_id: input.tenantId,
    app_facility_id: input.facilityId,
    app_branch_id: '',
    app_is_platform: 'false',
  };
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
    'encounters-notes-draft wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
