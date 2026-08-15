/**
 * invoices-show — the single-invoice READ Edge Function (Phase 17).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/invoices_show.ts (pure,
 * dependency-free, proven by the local harness). This file only wires the
 * Supabase runtime. It is NOT executed locally — no Deno/Supabase runtime
 * exists in this environment (see supabase/README.md, "validation tiers").
 *
 * The RLS-scoped read is the production-critical wiring (BillingController
 * ::showInvoice + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the invoice SELECT by id runs as swasthya_app — the claims-scoped
 *      invoices policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth only — the id is a resource selector, never
 *      authorization scope;
 *   3. invoice_lines is a TENANT_ONLY claims-scoped table (no facility
 *      clause) — the lines SELECT is bound to the already-verified invoice
 *      id and ordered by line_no exactly as `Invoice::lines()`;
 *   4. the presenter shape mirrors presentInvoice exactly: header fields +
 *      lines only — the Laravel show contract carries NO payments or
 *      allocations (those belong to the separate `invoices/{invoice}/
 *      payments` route).
 *
 * No mutations. No client-supplied tenant/facility/branch value ever
 * becomes authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleInvoicesShow } from '../_shared/invoices_show.ts';
import type { InvoicesShowDeps, InvoiceLineRow, InvoiceShowRow } from '../_shared/invoices_show.ts';
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

const deps: InvoicesShowDeps = {
  ...identityDeps,
  showInvoice: (claims: Claims, id: string) => {
    // The claims are server-derived. Set the GUC, then let RLS decide
    // visibility; the explicit scope WHERE is defense-in-depth.
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<InvoiceShowDbRow>(
      `select id, invoice_number, facility_id, patient_id, status,
              total_minor, total_tax_minor, paid_minor, issued_at, lock_version
         from public.invoices
        where id = $3
          and tenant_id = $1 and facility_id = $2
        limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    if (row === undefined) return null;

    // invoice_lines is TENANT_ONLY claims-scoped — RLS applies the tenant
    // claim; the invoice_id binding ties the lines to the verified header.
    const lines = db.queryObject<InvoiceLineDbRow>(
      `select id, description, amount_minor, tax_minor
         from public.invoice_lines
        where invoice_id = $1
        order by line_no asc`,
      [id],
    ).rows.map((line) => ({
      id: line.id,
      description: line.description,
      amountMinor: line.amount_minor,
      taxMinor: line.tax_minor,
    }));

    const invoice: InvoiceShowRow = {
      id: row.id,
      invoiceNumber: row.invoice_number,
      facilityId: row.facility_id,
      patientId: row.patient_id,
      status: row.status,
      totalMinor: row.total_minor,
      totalTaxMinor: row.total_tax_minor,
      paidMinor: row.paid_minor,
      issuedAt: row.issued_at,
      lockVersion: row.lock_version,
    };

    return { invoice, lines };
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

Deno.serve((req) => handleInvoicesShow(req, deps));

/* ------------------------------------------------------------------ */

interface InvoiceShowDbRow {
  id: string;
  invoice_number: string;
  facility_id: string;
  patient_id: string;
  status: string;
  total_minor: number;
  total_tax_minor: number;
  paid_minor: number;
  issued_at: string | null;
  lock_version: number;
}

interface InvoiceLineDbRow {
  id: string;
  description: string;
  amount_minor: number;
  tax_minor: number;
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
    'invoices-show wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
