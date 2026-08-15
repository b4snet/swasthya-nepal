/**
 * invoices-pay — the payment CAPTURE Edge Function (Phase 16).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/invoices_pay.ts (pure,
 * dependency-free, proven by the local harness). This file only wires the
 * Supabase runtime. It is NOT executed locally — no Deno/Supabase runtime
 * exists in this environment (see supabase/README.md, "validation tiers").
 *
 * Production-critical wiring preserved from the Laravel source of truth
 * (BillingController::pay + BillingService::capturePayment):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the invoice lookup runs as swasthya_app — RLS is the final boundary
 *      (invoices is claims-scoped, tenant+facility);
 *   3. the WHOLE capture runs in ONE transaction:
 *        a. IDEMPOTENCY FIRST: a payment with the same (tenant,
 *           idempotency_key) is replayed — no new money, no eligibility
 *           checks, HTTP 200 (the unique index uq_payments_tenant_idempotency
 *           is the CONCURRENT backstop → retryable 409, never a 500);
 *        b. invoice eligibility: voided → 409; already paid → 409; amount
 *           must be positive and cannot exceed the outstanding balance →
 *           422 (exact Laravel messages);
 *        c. payment INSERT (currency NPR, status captured, received_at and
 *           created_by server-derived) + payment_allocations INSERT;
 *        d. the GUARDED optimistic-lock invoice update
 *           (`paid_minor = paid_minor + amount, status = paid |
 *           partially_paid, lock_version = lock_version + 1 WHERE id = … AND
 *           tenant_id = … AND lock_version = <expected>`) — the DB decides
 *           the winner; the loser matches zero rows, the WHOLE transaction
 *           rolls back (no orphan payment/allocation), and the exact Laravel
 *           LOCK_CONFLICT 409 is returned;
 *   4. the client can send payment DATA (method/amount/idempotencyKey/
 *      providerRef) but NEVER invoice status, lock_version, tenant/facility,
 *      allocation ownership, or timestamps;
 *   5. audit appends mirror AuditLogger exactly (advisory lock + prev_hash +
 *      sha256 chain), attributed to the actor + authoritative context.
 *
 * No client-supplied tenant/facility/branch/lock_version/status value ever
 * becomes authoritative; no SECURITY DEFINER; no service-role credentials.
 */
import { handleInvoicesPay } from '../_shared/invoices_pay.ts';
import type {
  CapturePaymentInput,
  CapturePaymentResult,
  InvoicesPayDeps,
  InvoicePayRow,
  PaymentPresentation,
} from '../_shared/invoices_pay.ts';
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

const deps: InvoicesPayDeps = {
  ...identityDeps,

  findInvoiceByScope: (claims: Claims, id: string): InvoicePayRow | null => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claims)]);
    const row = db.queryObject<InvoiceDbRow>(
      `select id, facility_id, patient_id, invoice_number, status,
              total_minor, total_tax_minor, paid_minor, lock_version
         from public.invoices
        where id = $3 and tenant_id = $1 and facility_id = $2 limit 1`,
      [claims.app_tenant_id === '' ? null : claims.app_tenant_id, claims.app_facility_id === '' ? null : claims.app_facility_id, id],
    ).rows[0];
    return row ? mapInvoice(row) : null;
  },

  capturePayment: (input: CapturePaymentInput): CapturePaymentResult => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claimsFor(input))]);
    const tx = db.transaction();
    try {
      // (a) IDEMPOTENCY FIRST (BillingService::capturePayment parity): the
      // same (tenant, idempotency_key) replays the ORIGINAL payment — no new
      // money, no eligibility checks. The response's invoice block reflects
      // the CURRENT state of the URL invoice (controller parity: refresh()).
      const existing = tx.queryObject<PaymentDbRow>(
        `select id, facility_id, patient_id, method, provider_ref, amount_minor, status, received_at::text
           from public.payments
          where tenant_id = $1 and idempotency_key = $2 limit 1`,
        [input.tenantId, input.idempotencyKey],
      ).rows[0];
      if (existing !== undefined) {
        const invoice = tx.queryObject<InvoiceDbRow>(
          `select id, facility_id, patient_id, invoice_number, status,
                  total_minor, total_tax_minor, paid_minor, lock_version
             from public.invoices
            where id = $2 and tenant_id = $1 limit 1`,
          [input.tenantId, input.invoiceId],
        ).rows[0];
        const payment = presentPayment(existing, invoice);
        tx.commit();
        return { ok: true, replayed: true, payment };
      }

      // (b) Invoice eligibility — the exact Laravel checks + messages.
      const invoice = tx.queryObject<InvoiceDbRow>(
        `select id, facility_id, patient_id, invoice_number, status,
                total_minor, total_tax_minor, paid_minor, lock_version
           from public.invoices
          where id = $2 and tenant_id = $1 limit 1`,
        [input.tenantId, input.invoiceId],
      ).rows[0];
      if (invoice === undefined) {
        tx.rollback();
        return { ok: false, reason: 'INVOICE_NOT_FOUND' };
      }
      if (invoice.status === 'voided') {
        tx.rollback();
        return { ok: false, reason: 'VOIDED' };
      }
      if (Number(invoice.paid_minor) >= Number(invoice.total_minor)) {
        tx.rollback();
        return { ok: false, reason: 'ALREADY_PAID' };
      }
      if (input.amountMinor <= 0) {
        tx.rollback();
        return { ok: false, reason: 'AMOUNT_INVALID' };
      }
      const remaining = Number(invoice.total_minor) - Number(invoice.paid_minor);
      if (input.amountMinor > remaining) {
        tx.rollback();
        return { ok: false, reason: 'EXCEEDS_BALANCE', amountMinor: input.amountMinor, remaining };
      }

      // (c) Payment + allocation INSERT — committed ONLY with the guarded
      // update below (a failed lock rolls BOTH back).
      const payment = tx.queryObject<PaymentDbRow>(
        `insert into public.payments
           (id, tenant_id, facility_id, patient_id, method, provider_ref, amount_minor,
            currency, status, idempotency_key, received_by, received_at, created_by, created_at, updated_at)
         values (gen_random_uuid(), $1, $2, $3, $4, $5, $6, 'NPR', 'captured', $7, $8, now(), $8, now(), now())
         returning id, facility_id, patient_id, method, provider_ref, amount_minor, status, received_at::text`,
        [input.tenantId, input.facilityId, input.patientId, input.method, input.providerRef, input.amountMinor, input.idempotencyKey, input.receivedBy],
      ).rows[0];

      tx.execute(
        `insert into public.payment_allocations
           (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at, created_by, created_at, updated_at)
         values (gen_random_uuid(), $1, $2, $3, $4, now(), $5, now(), now())`,
        [input.tenantId, payment.id, input.invoiceId, input.amountMinor, input.receivedBy],
      );

      // (d) The GUARDED optimistic-lock invoice update — the DB decides the
      // winner. `lock_version = <expected>` where expected is the version
      // the pre-transaction read observed (Laravel parity). A stale match
      // yields zero rows → LOCK_CONFLICT → the whole transaction rolls back.
      const newPaid = Number(invoice.paid_minor) + input.amountMinor;
      const newStatus = newPaid >= Number(invoice.total_minor) ? 'paid' : 'partially_paid';
      const affected = tx.execute(
        `update public.invoices
            set paid_minor = paid_minor + $4, status = $5, lock_version = lock_version + 1, updated_at = now()
          where id = $1 and tenant_id = $2 and lock_version = $3`,
        [input.invoiceId, input.tenantId, input.expectedLockVersion, input.amountMinor, newStatus],
      );
      if (affected !== 1) {
        tx.rollback();
        return { ok: false, reason: 'LOCK_CONFLICT' };
      }

      tx.commit();
      return {
        ok: true,
        replayed: false,
        payment: {
          paymentId: payment.id,
          status: payment.status,
          amountMinor: Number(payment.amount_minor),
          method: payment.method,
          replayed: false,
          invoice: {
            id: invoice.id,
            invoiceNumber: invoice.invoice_number,
            status: newStatus,
            totalMinor: Number(invoice.total_minor),
            paidMinor: newPaid,
          },
        } satisfies PaymentPresentation,
      };
    } catch (error) {
      tx.rollback();
      // Concurrent same-key race: the winner committed first; the unique
      // index uq_payments_tenant_idempotency rejected this insert. A retry
      // REPLAYS the winner's payment (the idempotency lookup now finds it).
      if (isUniqueViolation(error) && uniqueConstraintName(error) === 'uq_payments_tenant_idempotency') {
        return { ok: false, reason: 'IDEMPOTENCY_RACE' };
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

Deno.serve((req) => handleInvoicesPay(req, deps));

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

interface InvoiceDbRow {
  id: string;
  facility_id: string;
  patient_id: string;
  invoice_number: string;
  status: string;
  total_minor: string | number;
  total_tax_minor: string | number;
  paid_minor: string | number;
  lock_version: string | number;
}

interface PaymentDbRow {
  id: string;
  facility_id: string;
  patient_id: string;
  method: string;
  provider_ref: string | null;
  amount_minor: string | number;
  status: string;
  received_at: string;
}

function mapInvoice(row: InvoiceDbRow): InvoicePayRow {
  return {
    id: row.id,
    facilityId: row.facility_id,
    patientId: row.patient_id,
    invoiceNumber: row.invoice_number,
    status: row.status,
    totalMinor: Number(row.total_minor),
    totalTaxMinor: Number(row.total_tax_minor),
    paidMinor: Number(row.paid_minor),
    lockVersion: Number(row.lock_version),
  };
}

function presentPayment(payment: PaymentDbRow, invoice: InvoiceDbRow | undefined): PaymentPresentation {
  return {
    paymentId: payment.id,
    status: payment.status,
    amountMinor: Number(payment.amount_minor),
    method: payment.method,
    replayed: true,
    invoice: {
      id: invoice?.id ?? '',
      invoiceNumber: invoice?.invoice_number ?? '',
      status: invoice?.status ?? '',
      totalMinor: invoice ? Number(invoice.total_minor) : 0,
      paidMinor: invoice ? Number(invoice.paid_minor) : 0,
    },
  };
}

/** The server-derived claims for the mutation GUC (from the pipeline result). */
function claimsFor(input: CapturePaymentInput): Claims {
  return {
    app_user_id: input.receivedBy ?? '',
    app_tenant_id: input.tenantId,
    app_facility_id: input.facilityId,
    app_branch_id: '',
    app_is_platform: 'false',
  };
}

/** postgres.js surfaces PostgreSQL errors with a numeric `code` string. */
function isUniqueViolation(error: unknown): boolean {
  return typeof error === 'object' && error !== null && (error as { code?: unknown }).code === '23505';
}

function uniqueConstraintName(error: unknown): string {
  const row = error as { constraint_name?: unknown; constraintName?: unknown };
  return String(row.constraint_name ?? row.constraintName ?? '');
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
  execute(sql: string, params?: unknown[]): number;
  queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
  transaction(): {
    execute(sql: string, params?: unknown[]): number;
    queryObject<T>(sql: string, params?: unknown[]): { rows: T[] };
    commit(): void;
    rollback(): void;
  };
} {
  throw new Error(
    'invoices-pay wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
