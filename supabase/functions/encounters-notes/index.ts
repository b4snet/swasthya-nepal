/**
 * encounters-notes — the clinical notes of one encounter Edge Function
 * (Phase 25).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/encounter_notes_list.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (EncounterController
 * ::notes + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the encounter gate SELECT runs as swasthya_app — the claims-scoped
 *      encounters policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth — the id is a resource selector, never
 *      authorization scope;
 *   3. the clinical_notes SELECT is bound to the VERIFIED encounter id and
 *      the tenant claim (clinical_notes is a TENANT_ONLY claims-scoped
 *      table — the encounter gate already scoped the caller to this
 *      facility) and ordered by created_at ascending — the exact
 *      `->orderBy('created_at')`; ALL statuses return (draft, signed,
 *      amended — the Laravel hasMany applies no status filter);
 *   4. the author REF resolves in the same query under the same claims via
 *      a LEFT JOIN on staff (TENANT_FACILITY) — an out-of-scope author
 *      joins to NULL and renders null (the established Phase 18/21 parity),
 *      never a leak;
 *   5. NO audit — EncounterController::notes records no audit event.
 *
 * No client-supplied tenant/facility/branch value ever becomes
 * authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleEncountersNotes } from '../_shared/encounter_notes_list.ts';
import type { EncountersNotesDeps, EncounterNoteRow } from '../_shared/encounter_notes_list.ts';
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

const deps: EncountersNotesDeps = {
  ...identityDeps,

  listEncounterNotes: (claims: Claims, id: string): EncounterNoteRow[] | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);

    // The 404 gate: the encounter itself must be visible under the claims
    // (TENANT_FACILITY). Out-of-scope ≡ nonexistent → null.
    const encounter = db.queryObject<{ id: string }>(
      'select id from public.encounters where id = $3 and tenant_id = $1 and facility_id = $2 limit 1',
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    if (encounter === undefined) return null;

    // clinical_notes is TENANT_ONLY — the tenant claim + the verified
    // encounter binding scope the read; ordered by created_at ascending
    // (the exact Laravel order). The author ref joins under the same claims
    // (staff is TENANT_FACILITY; coalesce = the null-safe facilityClause).
    const rows = db.queryObject<EncounterNoteDbRow>(
      `select n.id, n.note_type, n.content, n.status, n.signed_at::text,
              s.id as author_id, s.full_name as author_full_name
         from public.clinical_notes n
         left join public.staff s
                on s.id = n.author_staff_id and s.tenant_id = n.tenant_id
               and coalesce($2, s.facility_id) = s.facility_id and s.status <> 'departed'
        where n.encounter_id = $3 and n.tenant_id = $1
        order by n.created_at asc`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows;

    return rows.map((row) => ({
      id: row.id,
      noteType: row.note_type,
      author: row.author_id !== null ? { id: row.author_id, fullName: row.author_full_name } : null,
      content: row.content,
      status: row.status,
      signedAt: row.signed_at,
    }));
  },
};

Deno.serve((req) => handleEncountersNotes(req, deps));

/* ------------------------------------------------------------------ */

interface EncounterNoteDbRow {
  id: string;
  note_type: string;
  content: Record<string, unknown>;
  status: string;
  signed_at: string | null;
  author_id: string | null;
  author_full_name: string | null;
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
    'encounters-notes wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
