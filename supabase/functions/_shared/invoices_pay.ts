/**
 * The `invoices:pay` domain function (pure request handler, Phase 16) — a
 * payment is captured and allocated against an issued invoice, mirroring the
 * established Laravel contract exactly (BillingController::pay +
 * BillingService::capturePayment). The Laravel behavior is the source of
 * truth; this function executes it through the secure pipeline + the same
 * RLS-scoped database.
 *
 * Payment invariants preserved:
 *   - IDEMPOTENCY FIRST (Laravel parity): a request whose (tenant,
 *     idempotencyKey) already produced a payment REPLAYS that payment — no
 *     new money, no eligibility checks, HTTP 200 with `replayed: true`; the
 *     unique index uq_payments_tenant_idempotency is the concurrent backstop;
 *   - invoice eligibility (after idempotency): a `voided` invoice cannot be
 *     paid (409); an invoice with paid_minor >= total_minor is already paid
 *     (409); the amount must be positive (422) and cannot exceed the
 *     outstanding balance (422) — EXACT Laravel messages;
 *   - money is integer minor units end to end (DATABASE.md §0.4);
 *   - the capture is ONE transaction: payment INSERT + allocation INSERT +
 *     the GUARDED optimistic-lock invoice update
 *     (`paid_minor = paid_minor + amount, status = paid | partially_paid,
 *      lock_version = lock_version + 1 WHERE id = … AND lock_version =
 *      <expected>`) — the DB decides the winner; a concurrent capture whose
 *     pre-read is stale matches zero rows and the WHOLE transaction rolls
 *     back (no orphan payment/allocation) → 409 LOCK_CONFLICT with the exact
 *     Laravel message;
 *   - `method` is restricted to the exact Payment methods
 *     (cash/card/wallet/bank/insurance), `currency` is always NPR.
 *
 * Flow:
 *  1. authenticate through the shared pipeline;
 *  2. authorize with `can(context, 'billing:collect')` (route-gate parity —
 *     the Laravel gate is `authorize:billing:collect`);
 *  3. STRICT request body (CapturePaymentRequest parity): `method`
 *     (required, enum), `amountMinor` (required, integer >= 1),
 *     `idempotencyKey` (required, 8..100), `providerRef` (optional, <= 100);
 *     unknown fields → 422 NOT_ALLOWED; malformed JSON → 400;
 *  4. the invoice id is a RESOURCE SELECTOR only — out-of-scope/malformed →
 *     404 (AccessCheck::scoped + implicit-binding parity); the pre-read also
 *     captures the invoice `lock_version` that the guarded update will
 *     compare against (the transaction's expected version — Laravel parity);
 *  5. the ATOMIC capture transaction (idempotency → eligibility → payment +
 *     allocation INSERT → guarded lock_version update) — failures map to the
 *     exact Laravel statuses/messages;
 *  6. audit `payment.captured` (new) or `payment.replayed` (replay), with
 *     the exact Laravel payload {invoiceId, method, amountMinor, replayed};
 *  7. return 201 (new) / 200 (replayed) with the exact
 *     BillingController::pay data shape.
 *
 * The client NEVER controls invoice status, lock_version, allocation
 * ownership, tenant/facility, or timestamps; forged app_* claims and forged
 * proposals are inert. RLS (swasthya_app, NOBYPASSRLS) is the final
 * boundary.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AuditEventInput } from './appointments_create.ts';

/** The exact Payment methods (Payment model parity). */
export const PAYMENT_METHODS = ['cash', 'card', 'wallet', 'bank', 'insurance'] as const;
/** The status every captured payment starts at (model parity). */
export const PAYMENT_STATUS_CAPTURED = 'captured';
/** The invoice statuses that affect payment eligibility (model parity). */
export const INVOICE_STATUS_VOIDED = 'voided';
export const INVOICE_STATUS_PAID = 'paid';
export const INVOICE_STATUS_PARTIALLY_PAID = 'partially_paid';

/** The RLS-visible invoice row the handler reads for scope + the expected
 * lock_version (mirror of the invoices columns the edge function needs). */
export interface InvoicePayRow {
  id: string;
  facilityId: string;
  patientId: string;
  invoiceNumber: string;
  status: string;
  totalMinor: number;
  totalTaxMinor: number;
  paidMinor: number;
  lockVersion: number;
}

/** The exact BillingController::pay data shape. */
export interface PaymentPresentation {
  paymentId: string;
  status: string;
  amountMinor: number;
  method: string;
  replayed: boolean;
  invoice: {
    id: string;
    invoiceNumber: string;
    status: string;
    totalMinor: number;
    paidMinor: number;
  };
}

export interface CapturePaymentInput {
  tenantId: string;
  facilityId: string;
  patientId: string;
  invoiceId: string;
  /** The invoice lock_version observed by the pre-transaction read (the
   * transaction's expected version — Laravel parity: the service compares
   * against the controller-loaded invoice's version). */
  expectedLockVersion: number;
  method: string;
  amountMinor: number;
  idempotencyKey: string;
  providerRef: string | null;
  receivedBy: string | null;
}

export type CapturePaymentResult =
  | { ok: true; payment: PaymentPresentation; replayed: boolean }
  | { ok: false; reason: 'INVOICE_NOT_FOUND' | 'VOIDED' | 'ALREADY_PAID' | 'AMOUNT_INVALID' | 'EXCEEDS_BALANCE' | 'LOCK_CONFLICT' | 'IDEMPOTENCY_RACE' | 'ERROR'; amountMinor?: number; remaining?: number };

export interface InvoicesPayDeps extends HealthAuthDeps {
  /** RLS-scoped invoice lookup (swasthya_app under the claims); null covers
   * both nonexistent and out-of-scope (→ 404, existence never leaked). */
  findInvoiceByScope: (claims: Claims, id: string) => InvoicePayRow | null;
  /**
   * The ATOMIC capture transaction (mirror of BillingService::capturePayment):
   * idempotency FIRST (same tenant + key → replay, no new money, no
   * eligibility checks), then invoice eligibility, then the payment +
   * allocation INSERT, then the GUARDED optimistic-lock invoice update
   * (`WHERE id = … AND tenant_id = … AND lock_version = expected`). The
   * payment/allocation rows commit only with the guarded update — a
   * LOCK_CONFLICT rolls back the whole transaction (no orphan rows).
   * IDEMPOTENCY_RACE is the adapter's mapping of a concurrent same-key
   * insert that lost the uq_payments_tenant_idempotency unique-index race
   * (a sequential retry never sees it — it replays instead).
   */
  capturePayment: (input: CapturePaymentInput) => CapturePaymentResult;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

/** GoTrue/app invoice ids are UUIDs (the primary key of invoices). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The invoice id is the last non-empty URL segment (route parity). */
export function payInvoiceIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

export interface PayRequestValidationError {
  field: string;
  code: string;
  message: string;
}

function validationError(field: string, code: string, message: string): PayRequestValidationError {
  return { field, code, message };
}

/** CapturePaymentRequest parity (strict). */
export function validatePayBody(body: unknown): { ok: true; value: { method: string; amountMinor: number; idempotencyKey: string; providerRef: string | null } } | { ok: false; details: PayRequestValidationError[] } {
  if (typeof body !== 'object' || body === null || Array.isArray(body)) {
    return { ok: false, details: [validationError('body', 'INVALID_FORMAT', 'The request body must be a JSON object.')] };
  }

  const record = body as Record<string, unknown>;
  const errors: PayRequestValidationError[] = [];

  // Strict: unknown fields are rejected (the established edge contract).
  for (const key of Object.keys(record)) {
    if (!['method', 'amountMinor', 'idempotencyKey', 'providerRef'].includes(key)) {
      errors.push(validationError(key, 'NOT_ALLOWED', `Field "${key}" is not allowed.`));
    }
  }

  // Required fields (CapturePaymentRequest `required` rules).
  if (record.method === undefined) errors.push(validationError('method', 'REQUIRED', 'The method field is required.'));
  if (record.amountMinor === undefined) errors.push(validationError('amountMinor', 'REQUIRED', 'The amount minor field is required.'));
  if (record.idempotencyKey === undefined) errors.push(validationError('idempotencyKey', 'REQUIRED', 'The idempotency key field is required.'));

  if (errors.length > 0) return { ok: false, details: errors };

  // Value rules (CapturePaymentRequest `in` / `integer` / `min` / `max`).
  const method = record.method as string;
  if (typeof method !== 'string' || !PAYMENT_METHODS.includes(method as (typeof PAYMENT_METHODS)[number])) {
    errors.push(validationError('method', 'INVALID_VALUE', 'The selected method is invalid.'));
  }

  const amountMinor = record.amountMinor as number;
  if (typeof amountMinor !== 'number' || !Number.isInteger(amountMinor)) {
    errors.push(validationError('amountMinor', 'INVALID_VALUE', 'The amount minor must be an integer.'));
  } else if (amountMinor < 1) {
    errors.push(validationError('amountMinor', 'INVALID_VALUE', 'The amount minor must be at least 1.'));
  }

  const idempotencyKey = record.idempotencyKey as string;
  if (typeof idempotencyKey !== 'string') {
    errors.push(validationError('idempotencyKey', 'INVALID_VALUE', 'The idempotency key must be a string.'));
  } else {
    if (idempotencyKey.length < 8) errors.push(validationError('idempotencyKey', 'INVALID_VALUE', 'The idempotency key must be at least 8 characters.'));
    if (idempotencyKey.length > 100) errors.push(validationError('idempotencyKey', 'INVALID_VALUE', 'The idempotency key must be at most 100 characters.'));
  }

  const providerRef = record.providerRef;
  if (providerRef !== undefined && providerRef !== null) {
    if (typeof providerRef !== 'string') {
      errors.push(validationError('providerRef', 'INVALID_VALUE', 'The provider ref must be a string.'));
    } else if (providerRef.length > 100) {
      errors.push(validationError('providerRef', 'INVALID_VALUE', 'The provider ref must be at most 100 characters.'));
    }
  }

  if (errors.length > 0) return { ok: false, details: errors };

  return {
    ok: true,
    value: {
      method,
      amountMinor,
      idempotencyKey,
      providerRef: providerRef === undefined || providerRef === null ? null : (providerRef as string),
    },
  };
}

export async function handleInvoicesPay(req: Request, deps: InvoicesPayDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

  // Application authorization (defense-in-depth — RLS stays the final
  // boundary). Route-gate parity with `authorize:billing:collect`.
  if (!can(context, 'billing:collect')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  // Strict body contract (CapturePaymentRequest parity). The client CAN send
  // method/amount/idempotencyKey/providerRef — payment DATA — but NEVER
  // invoice status, lock_version, tenant/facility, or allocation ownership.
  let bodyText = '';
  try {
    bodyText = await req.text();
  } catch {
    bodyText = '';
  }
  if (bodyText.trim() === '') {
    return errorEnvelope(
      ErrorCodes.VALIDATION_ERROR,
      '1 field(s) failed validation.',
      422,
      correlationId,
      [validationError('body', 'REQUIRED', 'The request body is required.')],
    );
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(bodyText);
  } catch {
    return errorEnvelope(ErrorCodes.INVALID_REQUEST, 'The request is malformed.', 400, correlationId);
  }
  const body = validatePayBody(parsed);
  if (!body.ok) {
    return errorEnvelope(
      ErrorCodes.VALIDATION_ERROR,
      `${body.details.length} field(s) failed validation.`,
      422,
      correlationId,
      body.details,
    );
  }

  const invoiceId = payInvoiceIdFromUrl(req);

  // Missing/malformed identifier ≡ missing resource → 404 (implicit-binding
  // parity with Laravel's invoices/{invoice}).
  if (invoiceId === '' || !isUuid(invoiceId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope invoice resolves to null and
  // produces the SAME 404 as a nonexistent one (AccessCheck::scoped). The
  // pre-read also captures the expected lock_version for the guarded update.
  const invoice = deps.findInvoiceByScope(claims, invoiceId);
  if (invoice === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  const tenantId = context.organizationId ?? '';

  // The ATOMIC capture transaction — idempotency first, then eligibility,
  // then payment + allocation INSERT, then the GUARDED optimistic-lock
  // update. The DB decides the winner.
  const result = deps.capturePayment({
    tenantId,
    facilityId: invoice.facilityId,
    patientId: invoice.patientId,
    invoiceId: invoice.id,
    expectedLockVersion: invoice.lockVersion,
    method: body.value.method,
    amountMinor: body.value.amountMinor,
    idempotencyKey: body.value.idempotencyKey,
    providerRef: body.value.providerRef,
    receivedBy: user.id,
  });

  if (!result.ok) {
    switch (result.reason) {
      case 'INVOICE_NOT_FOUND':
        return errorEnvelope(ErrorCodes.NOT_FOUND, 'Invoice not found.', 404, correlationId);
      case 'VOIDED':
        return errorEnvelope(ErrorCodes.CONFLICT, 'A voided invoice cannot be paid.', 409, correlationId);
      case 'ALREADY_PAID':
        return errorEnvelope(ErrorCodes.CONFLICT, 'This invoice is already paid.', 409, correlationId);
      case 'AMOUNT_INVALID':
        return errorEnvelope(ErrorCodes.VALIDATION_ERROR, 'Payment amount must be positive.', 422, correlationId);
      case 'EXCEEDS_BALANCE':
        return errorEnvelope(
          ErrorCodes.VALIDATION_ERROR,
          `Payment of ${result.amountMinor} exceeds the outstanding balance of ${result.remaining}.`,
          422,
          correlationId,
        );
      case 'LOCK_CONFLICT':
        // The exact Laravel optimistic-lock message + the LOCK_CONFLICT code.
        return errorEnvelope(
          ErrorCodes.LOCK_CONFLICT,
          'This invoice was changed by another payment. Reload and retry.',
          409,
          correlationId,
        );
      case 'IDEMPOTENCY_RACE':
        // A concurrent same-key capture won the unique-index race — this
        // request lost and was rolled back. Retrying REPLAYS the winner's
        // payment (the idempotency lookup now finds it).
        return errorEnvelope(
          ErrorCodes.CONFLICT,
          'A payment with this idempotency key was captured concurrently. Retry.',
          409,
          correlationId,
        );
      default:
        return errorEnvelope(ErrorCodes.SERVER_ERROR, 'An unexpected error occurred.', 500, correlationId);
    }
  }

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. `payment.captured`
  // for a new payment, `payment.replayed` for an idempotency replay —
  // BillingController::pay parity.
  await deps.recordAudit({
    action: result.replayed ? 'payment.replayed' : 'payment.captured',
    resourceType: 'payment',
    resourceId: result.payment.paymentId,
    actorId: user.id,
    tenantId,
    facilityId: invoice.facilityId,
    correlationId,
    payload: {
      invoiceId: invoice.id,
      method: result.payment.method,
      amountMinor: result.payment.amountMinor,
      replayed: result.replayed,
    },
  });

  return successEnvelope(
    result.payment,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
    result.replayed ? 200 : 201,
  );
}
