/**
 * The `invoices:payments` domain function (pure request handler, Phase 18) —
 * the payments list for one invoice, mirroring the established Laravel
 * contract exactly (BillingController::payments — the
 * `invoices/{invoice}/payments` route).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'billing:view')` capability
 *     (mirror of the Laravel route gate `authorize:billing:view` — the same
 *     gate as `invoices:show`);
 *  3. the invoice id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility scope comes exclusively from the authoritative
 *     context/claims;
 *  4. the RLS-scoped read runs as swasthya_app with request.jwt.claims set:
 *     the invoice lookup is claims-scoped (invoices is TENANT_FACILITY) and
 *     decides 404 semantics; the allocations + their payments are read under
 *     the same claims (payment_allocations TENANT_ONLY, payments
 *     TENANT_FACILITY — an allocation whose payment lies outside the
 *     caller's facility scope renders `method: null`, exactly like
 *     Laravel's `payment?->method`);
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): an invoice that does not exist AND an
 *     invoice that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved allocation fields — the exact
 *     BillingController::payments map: {paymentId, method, amountMinor,
 *     allocatedAt} — ordered by allocated_at ascending; `provider_ref` and
 *     `received_at` are loaded by Laravel but NEVER presented;
 *  7. NO audit — BillingController::payments does not record an audit event
 *     (unlike showInvoice, which emits `invoice.viewed`); adding one would
 *     invent behavior;
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No pagination. No invented fields. No RLS weakening. No
 * SECURITY DEFINER. No service-role credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { invoiceIdFromUrl } from './invoices_show.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';

/** One presented allocation (the exact BillingController::payments map).
 * `method` is nullable — Laravel renders `payment?->method`, so an
 * allocation whose payment is outside the caller's facility scope (or
 * missing) presents `null`. `allocatedAt` mirrors `allocated_at?->toIso8601String()`. */
export interface PaymentAllocationRow {
  paymentId: string;
  method: string | null;
  amountMinor: number;
  allocatedAt: string | null;
}

export interface InvoicesPaymentsDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped payments read (swasthya_app under the claims; the
   * invoice id is a resource selector). Resolves the invoice under the
   * authoritative tenant + facility claims first — out-of-scope ≡
   * nonexistent → null → 404. Returns the invoice's allocations (ordered by
   * allocated_at ascending, the exact `->orderBy('allocated_at')`) with each
   * payment's method resolved under the same claims (null when the payment
   * is outside the caller's scope). No mutation.
   */
  listInvoicePayments: (claims: Claims, id: string) => PaymentAllocationRow[] | null;
}

export async function handleInvoicesPayments(req: Request, deps: InvoicesPaymentsDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, context, claims } = authentication.result;

  // Application authorization layer (defense-in-depth — RLS stays the final
  // boundary). Same capability and denial contract as the Laravel gate
  // `authorize:billing:view`.
  if (!can(context, 'billing:view')) {
    return errorEnvelope(
      ErrorCodes.SCOPE_DENIED,
      'You are not authorized to perform this action.',
      403,
      correlationId,
    );
  }

  const invoiceId = invoiceIdFromUrl(req);

  // A missing or malformed identifier is indistinguishable from a missing
  // resource — 404, never 400/422 (Laravel's implicit binding resolves to
  // the same ModelNotFoundException).
  if (invoiceId === '' || !isUuid(invoiceId)) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // RLS decides visibility: an out-of-scope invoice resolves to null here
  // and produces the SAME 404 as a nonexistent one (AccessCheck::scoped,
  // reads). The id is a resource selector — never authorization scope.
  const payments = deps.listInvoicePayments(claims, invoiceId);

  if (payments === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  // The exact BillingController::payments data shape: the bare allocation
  // list (already ordered by allocated_at by the RLS-scoped read), wrapped
  // in the standard envelope. No audit — the Laravel read does not audit.
  return successEnvelope(
    payments,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}

/** GoTrue/app invoice ids are UUIDs (the primary key of public.invoices). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
