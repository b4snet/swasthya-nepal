/**
 * invoices-payments — the payments list for one invoice Edge Function
 * (Phase 18).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/invoices_payments.ts
 * (pure, dependency-free, proven by the local harness). This file only
 * wires the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md, "validation
 * tiers").
 *
 * The RLS-scoped read is the production-critical wiring (BillingController
 * ::payments + AccessCheck::scoped parity):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the invoice SELECT by id runs as swasthya_app — the claims-scoped
 *      invoices policy is the FINAL boundary; an out-of-scope row is
 *      filtered out (→ null → 404); the explicit tenant/facility WHERE is
 *      defense-in-depth only — the id is a resource selector, never
 *      authorization scope;
 *   3. the allocations are read under the same claims: payment_allocations
 *      is TENANT_ONLY, payments is TENANT_FACILITY — the LEFT JOIN to
 *      payments leaves an allocation whose payment is outside the caller's
 *      facility scope (or missing) with `method = null`, exactly like
 *      Laravel's `payment?->method`; ordered by allocated_at ascending
 *      (`->orderBy('allocated_at')`);
 *   4. the presented shape mirrors BillingController::payments exactly:
 *      {paymentId, method, amountMinor, allocatedAt} — provider_ref /
 *      received_at are loaded by Laravel but never presented;
 *   5. NO audit — BillingController::payments records no audit event.
 *
 * No mutations. No client-supplied tenant/facility/branch value ever
 * becomes authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleInvoicesPayments } from '../_shared/invoices_payments.ts';
import type { InvoicesPaymentsDeps, PaymentAllocationRow } from '../_shared/invoices_payments.ts';
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

const deps: InvoicesPaymentsDeps = {
  ...identityDeps,
  listInvoicePayments: (claims: Claims, id: string): PaymentAllocationRow[] | null => {
    // The claims are server-derived. Set the GUC, then let RLS decide
    // visibility; the explicit scope WHERE on the invoice lookup is
    // defense-in-depth.
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const invoice = db.queryObject<{ id: string }>(
      `select id
         from public.invoices
        where id = $3
          and tenant_id = $1 and facility_id = $2
        limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    if (invoice === undefined) return null;

    // Allocations under the same claims (payment_allocations TENANT_ONLY),
    // joined to payments (TENANT_FACILITY) so an out-of-scope/missing
    // payment renders method = null — `payment?->method` parity. Ordered by
    // allocated_at exactly as `->orderBy('allocated_at')`.
    const rows = db.queryObject<PaymentAllocationDbRow>(
      `select pa.payment_id, pa.amount_minor, pa.allocated_at, p.method
         from public.payment_allocations pa
         left join public.payments p on p.id = pa.payment_id and p.tenant_id = pa.tenant_id
        where pa.invoice_id = $1
        order by pa.allocated_at asc`,
      [id],
    ).rows;

    // Present ONLY the approved allocation fields — provider_ref and
    // received_at are loaded by Laravel but never returned.
    return rows.map((row) => ({
      paymentId: row.payment_id,
      method: row.method ?? null,
      amountMinor: row.amount_minor,
      allocatedAt: row.allocated_at,
    }));
  },
};

Deno.serve((req) => handleInvoicesPayments(req, deps));

/* ------------------------------------------------------------------ */

interface PaymentAllocationDbRow {
  payment_id: string;
  amount_minor: number;
  allocated_at: string | null;
  method: string | null;
}

/** @internal placeholder — replaced by the real driver import in deployment. */
function postgresFromEnv(): {
  execute(sql: string, params?: unknown[]): void;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
} {
  throw new Error(
    'invoices-payments wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
