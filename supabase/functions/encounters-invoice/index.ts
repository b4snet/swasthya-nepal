/**
 * encounters-invoice — the invoice ISSUE Edge Function (Phase 15).
 *
 * THIN DENO ADAPTER: all logic lives in ../_shared/encounters_invoice.ts
 * (pure, dependency-free, proven by the local harness). This file only wires
 * the Supabase runtime. It is NOT executed locally — no Deno/Supabase
 * runtime exists in this environment (see supabase/README.md,
 * "validation tiers").
 *
 * Production-critical wiring preserved from the Laravel source of truth
 * (EncounterController::invoice + BillingService::issueInvoice):
 *   1. request.jwt.claims is set on the function's least-privilege
 *      connection (swasthya_app, NOBYPASSRLS) from the SERVER-DERIVED
 *      claims returned by the pipeline;
 *   2. the encounter lookup runs as swasthya_app — RLS is the final
 *      boundary (encounters is claims-scoped);
 *   3. the WHOLE issue runs in ONE transaction:
 *        a. re-read the encounter — still `signed` (defense-in-depth; the
 *           signed state is terminal);
 *        b. derive + insert the consultation charge from the appointment's
 *           service `default_charge_minor` ONLY when no encounter-source
 *           charge exists (idempotent, `insert … select … where not exists`);
 *        c. derive + insert the prescription-line charges (ordered lines ×
 *           medication `price_minor`, quantity = max(1, quantity_minor ?? 1))
 *           ONLY when the encounter's first prescription is not yet charged;
 *        d. load the posted charge ids — none → 409 'This encounter has no
 *           charges to bill.';
 *        e. reject already-invoiced charges → 409 (and the partial unique
 *           index uq_invoice_lines_tenant_charge is the CONCURRENT backstop
 *           — a racing second issue violates it and maps to the SAME 409);
 *        f. generate the invoice number server-side ('INV-YYYYMMDD-XXXXX',
 *           BillingService::nextNumber parity) and insert the invoice +
 *           frozen lines — uq_invoices_tenant_number is the concurrent
 *           number-collision backstop (retryable 409, never a raw 500);
 *        g. COMMIT — any failure rolls the whole transaction back (no
 *           partial charges/invoice/lines).
 *   4. every monetary value is an integer minor unit computed from
 *      authoritative DB rows — the client can never supply prices, totals,
 *      or the invoice number;
 *   5. audit appends mirror AuditLogger exactly (advisory lock + prev_hash +
 *      sha256 chain), attributed to the actor + authoritative context.
 *
 * No client-supplied tenant/facility/branch/patient/invoice-number/amount/
 * total value ever becomes authoritative; no SECURITY DEFINER; no
 * service-role credentials.
 */
import { handleEncountersInvoice } from '../_shared/encounters_invoice.ts';
import type {
  EncountersInvoiceDeps,
  IssueInvoiceInput,
  IssueInvoiceResult,
  InvoiceLinePresentation,
  InvoicePresentation,
} from '../_shared/encounters_invoice.ts';
import type { AuditEventInput } from '../_shared/appointments_create.ts';
import type { Claims } from '../_shared/claims.ts';
import type { HealthAuthDeps } from '../_shared/pipeline.ts';
import type { EncounterRow } from '../_shared/encounters_create.ts';

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

const deps: EncountersInvoiceDeps = {
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

  issueInvoice: (input: IssueInvoiceInput): IssueInvoiceResult => {
    db.execute('select set_config($1, $2, true)', ['request.jwt.claims', JSON.stringify(claimsFor(input))]);
    const tx = db.transaction();
    try {
      // (a) The encounter must still be signed (defense-in-depth — signed is
      // a terminal state, so this only catches a stale pre-read).
      const signed = tx.queryObject<{ status: string }>(
        `select status from public.encounters
          where id = $1 and tenant_id = $2 and facility_id = $3 and status = 'signed' limit 1`,
        [input.encounterId, input.tenantId, input.facilityId],
      ).rows[0];
      if (signed === undefined) {
        tx.rollback();
        return { ok: false, reason: 'NOT_SIGNED' };
      }

      // (b) Consultation charge — derived from the appointment's service
      // rate ONLY when no encounter-source charge exists yet (idempotent,
      // Laravel parity). Integer minor units end to end.
      tx.execute(
        `insert into public.charges
           (id, tenant_id, facility_id, patient_id, source_type, encounter_id,
            prescription_id, description, amount_minor, currency, tax_rate_bps,
            status, charged_at, created_by, created_at, updated_at)
         select gen_random_uuid(), a.tenant_id, $2, $3, 'encounter', $4, null,
                s.name || ' — consultation', s.default_charge_minor,
                coalesce(s.currency, 'NPR'), 0, 'posted', now(), $5, now(), now()
           from public.appointments a
           join public.services s on s.tenant_id = a.tenant_id and s.id = a.service_id
          where a.tenant_id = $1 and a.id = $6
            and s.default_charge_minor is not null
            and not exists (
              select 1 from public.charges c
               where c.tenant_id = $1 and c.encounter_id = $4 and c.source_type = 'encounter'
            )`,
        [input.tenantId, input.facilityId, input.patientId, input.encounterId, input.createdBy, input.appointmentId],
      );

      // (c) Prescription-line charges — ordered lines × medication price,
      // quantity = max(1, quantity_minor ?? 1); skipped when the encounter's
      // first prescription is already charged (Laravel parity). Lines with a
      // null/missing medication are excluded by the join — exactly the
      // `$line->medication === null → continue` rule.
      // Laravel anchors the already-charged check on the encounter's first
      // prescription id.
      const first = tx.queryObject<{ id: string }>(
        `select id from public.prescriptions
          where tenant_id = $1 and encounter_id = $2 order by id limit 1`,
        [input.tenantId, input.encounterId],
      ).rows[0];
      if (first !== undefined) {
          tx.execute(
            `insert into public.charges
               (id, tenant_id, facility_id, patient_id, source_type, encounter_id,
                prescription_id, description, amount_minor, currency, tax_rate_bps,
                status, charged_at, created_by, created_at, updated_at)
             select gen_random_uuid(), p.tenant_id, $2, $3, 'prescription', $4, p.id,
                    m.generic_name || ' (' || m.strength || ') × ' ||
                      greatest(1, coalesce(pl.quantity_minor, 1))::text,
                    m.price_minor * greatest(1, coalesce(pl.quantity_minor, 1)),
                    m.currency, 0, 'posted', now(), $5, now(), now()
               from public.prescriptions p
               join public.prescription_lines pl
                 on pl.tenant_id = p.tenant_id and pl.prescription_id = p.id
               join public.medications m on m.tenant_id = pl.tenant_id and m.id = pl.medication_id
              where p.tenant_id = $1 and p.encounter_id = $4 and pl.status = 'ordered'
                and not exists (
                  select 1 from public.charges c
                   where c.tenant_id = $1 and c.prescription_id = $6
                )`,
            [input.tenantId, input.facilityId, input.patientId, input.encounterId, input.createdBy, first.id],
          );
        }
      }

      // (d) Posted charge ids for this encounter — the invoice is built ONLY
      // from posted charges (voided/manual-cancelled charges are excluded).
      const charges = tx.queryObject<ChargeDbRow>(
        `select id, description, amount_minor, tax_rate_bps
           from public.charges
          where tenant_id = $1 and encounter_id = $2 and status = 'posted'
          order by charged_at, id`,
        [input.tenantId, input.encounterId],
      ).rows;

      if (charges.length === 0) {
        tx.rollback();
        return { ok: false, reason: 'NO_CHARGES' };
      }

      const chargeIds = charges.map((charge) => charge.id);

      // (e) A posted charge is invoiced at most once (BillingService parity) —
      // pre-check before inserting so the retry is a clean 409. The partial
      // unique index uq_invoice_lines_tenant_charge is the concurrent
      // backstop (a racing issue violates it → ALREADY_INVOICED below).
      const alreadyInvoiced = tx.queryObject<{ present: boolean }>(
        `select exists (
           select 1 from public.invoice_lines
            where tenant_id = $1 and charge_id = any($2::uuid[])
         ) as present`,
        [input.tenantId, chargeIds],
      ).rows[0];
      if (alreadyInvoiced?.present === true) {
        tx.rollback();
        return { ok: false, reason: 'ALREADY_INVOICED' };
      }

      // (f) Server-generated invoice number (BillingService::nextNumber
      // parity: 'INV-YYYYMMDD-XXXXX', random per day, retried while it
      // exists). The per-tenant unique index uq_invoices_tenant_number is the
      // concurrent backstop — a collision under concurrency maps to a
      // retryable 409, never a raw 500.
      const day = new Date().toISOString().slice(0, 10).replaceAll('-', '');
      let invoiceNumber = '';
      do {
        invoiceNumber = `INV-${day}-${randomDigits()}`;
        const clash = tx.queryObject<{ present: boolean }>(
          `select exists (
             select 1 from public.invoices
              where tenant_id = $1 and invoice_number = $2
           ) as present`,
          [input.tenantId, invoiceNumber],
        ).rows[0];
        if (clash?.present === true) invoiceNumber = '';
      } while (invoiceNumber === '');

      const totalMinor = charges.reduce((sum, charge) => sum + Number(charge.amount_minor), 0);
      const totalTaxMinor = charges.reduce(
        (sum, charge) => sum + Math.round((Number(charge.amount_minor) * Number(charge.tax_rate_bps)) / 10000),
        0,
      );

      const invoice = tx.queryObject<InvoiceDbRow>(
        `insert into public.invoices
           (id, tenant_id, facility_id, patient_id, invoice_number, status,
            total_minor, total_tax_minor, paid_minor, issued_at, lock_version,
            created_by, created_at, updated_at)
         values (gen_random_uuid(), $1, $2, $3, $4, 'issued', $5, $6, 0, now(), 0, $7, now(), now())
         returning id, invoice_number, status, total_minor, total_tax_minor, paid_minor`,
        [input.tenantId, input.facilityId, input.patientId, invoiceNumber, totalMinor, totalTaxMinor, input.createdBy],
      ).rows[0];

      // (g) Frozen line snapshots (description/amount/tax at issue time).
      const lines: InvoiceLinePresentation[] = charges.map((charge, index) => ({
        description: charge.description,
        amountMinor: Number(charge.amount_minor),
        taxMinor: Math.round((Number(charge.amount_minor) * Number(charge.tax_rate_bps)) / 10000),
      }));
      for (let index = 0; index < charges.length; index++) {
        const charge = charges[index];
        tx.execute(
          `insert into public.invoice_lines
             (id, tenant_id, invoice_id, charge_id, description, amount_minor,
              tax_minor, line_no, created_at, updated_at)
           values (gen_random_uuid(), $1, $2, $3, $4, $5, $6, $7, now(), now())`,
          [input.tenantId, invoice.id, charge.id, charge.description,
           Number(charge.amount_minor), lines[index].taxMinor, index + 1],
        );
      }

      tx.commit();
      return {
        ok: true,
        lineCount: charges.length,
        invoice: {
          id: invoice.id,
          invoiceNumber: invoice.invoice_number,
          status: invoice.status,
          totalMinor: Number(invoice.total_minor),
          totalTaxMinor: Number(invoice.total_tax_minor),
          paidMinor: Number(invoice.paid_minor),
          lines,
        } satisfies InvoicePresentation,
      };
    } catch (error) {
      tx.rollback();
      // Unique-violation backstops (postgres.js surfaces code 23505):
      //   uq_invoice_lines_tenant_charge — a charge was invoiced concurrently;
      //   uq_invoices_tenant_number    — the number collided concurrently.
      // Both are the exact conflicts the pre-checks return sequentially.
      if (isUniqueViolation(error)) {
        const constraint = uniqueConstraintName(error);
        if (constraint === 'uq_invoice_lines_tenant_charge') {
          return { ok: false, reason: 'ALREADY_INVOICED' };
        }
        if (constraint === 'uq_invoices_tenant_number') {
          return { ok: false, reason: 'NUMBER_COLLISION' };
        }
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

Deno.serve((req) => handleEncountersInvoice(req, deps));

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

interface ChargeDbRow {
  id: string;
  description: string;
  amount_minor: string | number;
  tax_rate_bps: string | number;
}

interface InvoiceDbRow {
  id: string;
  invoice_number: string;
  status: string;
  total_minor: string | number;
  total_tax_minor: string | number;
  paid_minor: string | number;
}

/** The server-derived claims for the mutation GUC (from the pipeline result). */
function claimsFor(input: IssueInvoiceInput): Claims {
  return {
    app_user_id: input.createdBy ?? '',
    app_tenant_id: input.tenantId,
    app_facility_id: input.facilityId,
    app_branch_id: '',
    app_is_platform: 'false',
  };
}

/** 5 random digits (BillingService `random_int(10000, 99999)` parity). */
function randomDigits(): number {
  const buffer = new Uint32Array(1);
  crypto.getRandomValues(buffer);
  return (buffer[0] % 90000) + 10000;
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
    'encounters-invoice wiring is not importable locally — run it inside Supabase (Deno) with the postgres driver; see supabase/README.md.',
  );
}
