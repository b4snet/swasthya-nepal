/**
 * health-auth — the first Swasthya Edge Function.
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/health_auth.ts (pure,
 * dependency-free, proven by the local harness). This file only wires the
 * Supabase runtime: environment, the HTTP entrypoint, and the real
 * PostgreSQL lookups. It is NOT executed locally — no Deno/Supabase runtime
 * exists in this environment (see supabase/README.md, "validation tiers").
 *
 * Deploy notes:
 *  - `SUPABASE_JWT_SECRET` is injected by Supabase for edge functions;
 *  - the lookups below query the public schema tables (users via
 *    auth_subject_id, role_assignments, organizations, facilities, branches,
 *    support_sessions) over the standard postgres driver;
 *  - DB queries run as the least-privilege application role with
 *    request.jwt.claims set from the server-derived claims — RLS remains the
 *    final boundary.
 */
import { handleHealthAuth } from '../_shared/health_auth.ts';
import type { HealthAuthDeps } from '../_shared/health_auth.ts';

// import { createClient } from 'npm:@supabase/supabase-js'; — alternative to
// the postgres driver below; choose one wiring in deployment.

const db = postgresFromEnv(); // deployed wiring — see supabase/README.md

const deps: HealthAuthDeps = {
  secret: Deno.env.get('SUPABASE_JWT_SECRET') ?? '',
  issuer: 'supabase', // GoTrue issuer
  audience: 'authenticated', // GoTrue audience for user JWTs
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
      role: {
        id: row.role_id,
        code: row.role_code,
        scopeType: row.role_scope_type,
        permissions: [], // loaded per-role in the full pipeline; health-auth
        //      only needs the scope/kind decision
      },
    })),
  activeSupportSession: (userId) => {
    const row = db.queryObject<{ id: string; organization_id: string; facility_id: string | null }>(
      `select id, organization_id, facility_id from public.support_sessions
        where user_id = $1 and status = 'active' and expires_at > now()
        order by opened_at desc limit 1`,
      [userId],
    ).rows[0];
    return row
      ? { id: row.id, organizationId: row.organization_id, facilityId: row.facility_id }
      : null;
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

Deno.serve((req) => handleHealthAuth(req, deps));

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

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'health-auth wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
