/**
 * The `encounters:invoice` domain function (pure request handler, Phase 15) —
 * the bill is issued from a SIGNED encounter, mirroring the established
 * Laravel contract exactly (EncounterController::invoice +
 * BillingService::issueInvoice). The Laravel behavior is the source of truth;
 * this function executes it through the secure pipeline + the same
 * RLS-scoped database.
 *
 * Billing invariants preserved:
 *   - ONLY a `signed` encounter may be billed ('Only a signed encounter can
 *     be billed.'); signed is a terminal state, so the pre-check has no race;
 *   - the consultation charge is derived server-side from the appointment's
 *     service `default_charge_minor` (integer minor units — never floats)
 *     ONLY when no encounter-source charge exists yet (idempotent);
 *   - prescription-line charges are derived server-side from ordered lines ×
 *     medication `price_minor`, quantity = max(1, quantity_minor ?? 1), ONLY
 *     when the encounter's first prescription has not been charged yet
 *     (Laravel parity);
 *   - money is integer minor units end to end (DATABASE.md §0.4); the tax
 *     line is round(amount_minor * tax_rate_bps / 10000), tax_rate_bps = 0
 *     for every auto-generated charge;
 *   - the invoice number is server-generated ('INV-YYYYMMDD-XXXXX', random
 *     per day, retried while it exists — BillingService::nextNumber parity);
 *     the per-tenant unique index uq_invoices_tenant_number is the final
 *     race boundary, and a posted charge is invoiced at most once (partial
 *     unique index uq_invoice_lines_tenant_charge) — both surface as the
 *     exact Laravel conflict message, never a raw 500;
 *   - the whole issue is ONE transaction: charge creation + already-invoiced
 *     check + invoice + lines commit together or not at all.
 *
 * Flow:
 *  1. authenticate through the shared pipeline;
 *  2. authorize with `can(context, 'billing:invoice')` (route-gate parity);
 *  3. strict body contract: NO body is accepted — invoice_number, amounts,
 *     totals, tenant/facility/patient/provider are all server-derived;
 *  4. the encounter id is a RESOURCE SELECTOR only — out-of-scope/malformed
 *     → 404 (AccessCheck::scoped + implicit-binding parity);
 *  5. eligibility: encounter.status must be 'signed' → else 409 with the
 *     exact Laravel message;
 *  6. the ATOMIC issue transaction (charge derivation, no-charges check,
 *     already-invoiced check, invoice + frozen lines, server-generated
 *     number) — the DB decides; failures map to the exact Laravel conflicts;
 *  7. audit 'invoice.issued' (actor + authoritative tenant/facility +
 *     correlation id + patientId/encounterId/totalMinor/lineCount);
 *  8. return 201 with the exact EncounterController::invoice data shape.
 *
 * The client NEVER controls scope, provider, invoice number, prices, totals,
 * or timestamps; forged app_* claims and forged proposals are inert. RLS
 * (swasthya_app, NOBYPASSRLS) is the final boundary.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AuditEventInput } from './appointments_create.ts';
import type { EncounterRow } from './encounters_create.ts';

/** The encounter status required for billing (model parity). */
export const ENCOUNTER_STATUS_SIGNED = 'signed';
/** The invoice status every issued invoice starts at (model parity). */
export const INVOICE_STATUS_ISSUED = 'issued';

/** Server-derived prescription-line facts (medication may be null — the line
 * is skipped, Laravel parity: `$line->medication === null` → continue). */
export interface MedicationRef {
  genericName: string;
  strength: string;
  priceMinor: number;
  currency: string;
}

export interface PrescriptionLineRef {
  status: string;
  quantityMinor: number | null;
  medication: MedicationRef | null;
}

export interface PrescriptionRef {
  id: string;
  lines: PrescriptionLineRef[];
}

/** A frozen invoice-line snapshot (the presentation shape). */
export interface InvoiceLinePresentation {
  description: string;
  amountMinor: number;
  taxMinor: number;
}

/** The exact EncounterController::invoice data shape (201). */
export interface InvoicePresentation {
  id: string;
  invoiceNumber: string;
  status: string;
  totalMinor: number;
  totalTaxMinor: number;
  paidMinor: number;
  lines: InvoiceLinePresentation[];
}

export interface IssueInvoiceInput {
  tenantId: string;
  facilityId: string;
  patientId: string;
  encounterId: string;
  appointmentId: string | null;
  createdBy: string | null;
}

export type IssueInvoiceResult =
  | { ok: true; invoice: InvoicePresentation; lineCount: number }
  | { ok: false; reason: 'NOT_SIGNED' | 'NO_CHARGES' | 'ALREADY_INVOICED' | 'NUMBER_COLLISION' | 'ERROR' };

export interface EncountersInvoiceDeps extends HealthAuthDeps {
  /** RLS-scoped encounter lookup (swasthya_app under the claims); null covers
   * both nonexistent and out-of-scope (→ 404, existence never leaked). */
  findEncounterByScope: (claims: Claims, id: string) => EncounterRow | null;
  /**
   * The ATOMIC issue transaction (mirror of EncounterController::invoice +
   * BillingService::issueInvoice): it re-reads the encounter (still signed?),
   * derives + inserts the consultation and prescription charges only when
   * absent, loads the posted charge ids, rejects an empty charge set, rejects
   * already-invoiced charges, generates the server-side invoice number, and
   * inserts the invoice + frozen lines — all in ONE transaction. Reasons:
   *   NOT_SIGNED       — the encounter is no longer signed (defense-in-depth;
   *                      signed is terminal, so this only guards a stale
   *                      pre-read);
   *   NO_CHARGES       — this encounter has no posted charges to bill (409);
   *   ALREADY_INVOICED — a charge is already on another invoice, including a
   *                      concurrent issue that lost the unique-index race
   *                      (409);
   *   NUMBER_COLLISION — the server-generated invoice number collided on
   *                      uq_invoices_tenant_number under concurrency (409,
   *                      retryable — the same retry BillingService::nextNumber
   *                      performs sequentially);
   *   ERROR            — any other failure; the whole transaction rolled back.
   */
  issueInvoice: (input: IssueInvoiceInput) => IssueInvoiceResult;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

/** GoTrue/app encounter ids are UUIDs (the primary key of encounters). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The encounter id is the last non-empty URL segment (route parity). */
export function invoiceEncounterIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

/** The `INV-YYYYMMDD-XXXXX` invoice-number format (BillingService parity). */
export function isInvoiceNumber(value: string): boolean {
  return /^INV-\d{8}-[0-9]{5}$/.test(value);
}

export async function handleEncountersInvoice(req: Request, deps: EncountersInvoiceDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization (defense-in-depth — RLS stays the final
  // boundary). Route-gate parity with `authorize:billing:invoice`.
  if (!can(context, 'billing:invoice')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  // Strict contract: issuing takes NO request body. Any JSON body with keys
  // is rejected — the client has nothing authoritative to send
  // (invoice_number, amounts, totals, timestamps are all server-derived).
  let bodyText = '';
  try {
    bodyText = await req.text();
  } catch {
    bodyText = '';
  }
  if (bodyText.trim() !== '') {
    let parsed: unknown;
    try {
      parsed = JSON.parse(bodyText);
    } catch {
      return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
    }
    if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
      return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
    }
    const fields = Object.keys(parsed as Record<string, unknown>);
    if (fields.length > 0) {
      return errorEnvelope(
        ErrorCodes.VALIDATION_ERROR,
        `${fields.length} field(s) failed validation.`,
        422,
        correlationId,
        fields.map((field) => ({
          field,
          code: 'NOT_ALLOWED',
          message: `Field "${field}" is not allowed.`,
        })),
      );
    }
  }

  const encounterId = invoiceEncounterIdFromUrl(req);

  // Missing/malformed identifier ≡ missing resource → 404 (implicit-binding
  // parity with Laravel's encounters/{encounter}).
  if (encounterId === '' || !isUuid(encounterId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope encounter resolves to null and
  // produces the SAME 404 as a nonexistent one (AccessCheck::scoped).
  const encounter = deps.findEncounterByScope(claims, encounterId);
  if (encounter === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // Eligibility (EncounterController::invoice parity). Signed is a terminal
  // state, so the pre-check has no race — the transaction re-verifies anyway.
  if (encounter.status !== ENCOUNTER_STATUS_SIGNED) {
    return errorEnvelope(          ErrorCodes.CONFLICT,
          'Only a signed encounter can be billed.',
          409,
      correlationId,
    );
  }

  const tenantId = context.organizationId ?? '';

  // The ATOMIC issue transaction — the DB derives the charges, computes the
  // totals in integer minor units, and enforces the uniqueness boundaries.
  const result = deps.issueInvoice({
    tenantId,
    facilityId: encounter.facilityId,
    patientId: encounter.patientId,
    encounterId: encounter.id,
    appointmentId: encounter.appointmentId,
    createdBy: user.id,
  });

  if (!result.ok) {
    switch (result.reason) {
      case 'NOT_SIGNED':
        return errorEnvelope(
        ErrorCodes.CONFLICT,
        'Only a signed encounter can be billed.',
        409,
          correlationId,
        );
      case 'NO_CHARGES':
        return errorEnvelope(
          ErrorCodes.CONFLICT,
          'This encounter has no charges to bill.',
          409,
          correlationId,
        );
      case 'ALREADY_INVOICED':
        return errorEnvelope(
          ErrorCodes.CONFLICT,
          'One or more charges have already been invoiced.',
          409,
          correlationId,
        );
      case 'NUMBER_COLLISION':
        // A concurrent issue drew the same invoice number; the unique index
        // rejected it and rolled the transaction back. Same retry contract
        // as BillingService::nextNumber's sequential loop.
        return errorEnvelope(
          ErrorCodes.CONFLICT,
          'The invoice number collided with a concurrent issue. Retry.',
          409,
          correlationId,
        );
      default:
        return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
    }
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. Facts only — no PHI,
  // no line-level detail.
  await deps.recordAudit({
    action: 'invoice.issued',
    resourceType: 'invoice',
    resourceId: result.invoice.id,
    actorId: user.id,
    tenantId,
    facilityId: encounter.facilityId,
    correlationId,
    payload: {
      patientId: encounter.patientId,
      encounterId: encounter.id,
      totalMinor: result.invoice.totalMinor,
      lineCount: result.lineCount,
    },
  });

  return successEnvelope(
    result.invoice,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
    201,
  );
}
