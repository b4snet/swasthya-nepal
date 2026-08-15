/**
 * The `invoices:show` domain function (pure request handler, Phase 17) — the
 * single-invoice READ (invoice header + lines), mirroring the established
 * Laravel contract exactly (BillingController::showInvoice + presentInvoice).
 *
 * Contract (documented in supabase/README.md):
 *  1. authenticate through the shared pipeline (JWT → sub → users →
 *     status gate → server-side context → five claims);
 *  2. authorize with the existing `can(context, 'billing:view')` capability
 *     (mirror of the Laravel route gate `authorize:billing:view`);
 *  3. the invoice id is a RESOURCE SELECTOR only — never authorization
 *     scope. Tenant/facility/branch scope comes exclusively from the
 *     authoritative context/claims;
 *  4. the RLS-scoped single-row query runs as swasthya_app with
 *     request.jwt.claims set — the claims-scoped invoices policy decides
 *     visibility;
 *  5. NOT-FOUND semantics match the established project convention exactly
 *     (AccessCheck::scoped, reads): an invoice that does not exist AND an
 *     invoice that exists but is outside the caller's scope BOTH return
 *     `404 NOT_FOUND` 'Resource not found.' — existence is never leaked;
 *  6. present ONLY the approved fields (InvoiceShowRow + ordered
 *     InvoiceLineRow[] — the exact BillingController::presentInvoice shape;
 *     the Laravel show contract carries NO payments/allocations — those live
 *     on the separate `invoices/{invoice}/payments` route);
 *  7. audit `invoice.viewed` with the exact Laravel payload
 *     {patientId} — BillingController::showInvoice parity;
 *  8. standard envelope/error/correlation contract; fail closed on every
 *     failure class.
 *
 * No mutations. No RLS weakening. No SECURITY DEFINER. No service-role
 * credentials.
 */
import { can } from './authorize.ts';
import { claimsComplete, type Claims } from './claims.ts';
import { error as errorEnvelope, success as successEnvelope } from './envelope.ts';
import { ErrorCodes } from './errors.ts';
import { authenticateRequest, type HealthAuthDeps } from './pipeline.ts';
import type { AuditEventInput } from './appointments_create.ts';

/** The RLS-visible invoice header the handler reads for scope + presentation
 * (mirror of the invoices columns the Laravel presenter uses). */
export interface InvoiceShowRow {
  id: string;
  invoiceNumber: string;
  facilityId: string;
  patientId: string;
  status: string;
  totalMinor: number;
  totalTaxMinor: number;
  paidMinor: number;
  issuedAt: string | null;
  lockVersion: number;
}

/** One invoice line, ordered by line_no (InvoiceLine model parity). */
export interface InvoiceLineRow {
  id: string;
  description: string;
  amountMinor: number;
  taxMinor: number;
}

/** The exact BillingController::presentInvoice data shape. */
export interface InvoiceShowPresentation {
  id: string;
  invoiceNumber: string;
  facilityId: string;
  patientId: string;
  status: string;
  totalMinor: number;
  totalTaxMinor: number;
  paidMinor: number;
  issuedAt: string | null;
  lockVersion: number;
  lines: InvoiceLineRow[];
}

export interface InvoicesShowDeps extends HealthAuthDeps {
  /**
   * The RLS-scoped single-invoice read (swasthya_app under the claims;
   * out-of-scope ≡ nonexistent → null). Runs as swasthya_app with
   * request.jwt.claims set; the explicit tenant/facility WHERE is
   * defense-in-depth. Returns the header + its lines ordered by line_no.
   */
  showInvoice: (claims: Claims, id: string) => {
    invoice: InvoiceShowRow;
    lines: InvoiceLineRow[];
  } | null;
  /** Append-only audit write (deployed wiring; may be async). */
  recordAudit: (event: AuditEventInput) => void | Promise<void>;
}

/** GoTrue/app invoice ids are UUIDs (the primary key of public.invoices). */
function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/** The invoice id is the last non-empty URL segment (route parity with
 * Laravel's `invoices/{invoice}`). */
export function invoiceIdFromUrl(req: Request): string {
  const segments = new URL(req.url).pathname.split('/').filter((segment) => segment !== '');
  return segments.length > 0 ? segments[segments.length - 1] : '';
}

export async function handleInvoicesShow(req: Request, deps: InvoicesShowDeps): Promise<Response> {
  const authentication = await authenticateRequest(req, deps);

  if (!authentication.ok) {
    return authentication.response;
  }

  const { correlationId, user, context, claims } = authentication.result;

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
  const found = deps.showInvoice(claims, invoiceId);

  if (found === null) {
    return errorEnvelope(ErrorCodes.NOT_FOUND, 'Resource not found.', 404, correlationId);
  }

  const { invoice, lines } = found;

  // Append-only audit, attributed to the authenticated actor + the
  // authoritative tenant/facility + the correlation id. `invoice.viewed`
  // with the exact Laravel payload {patientId} — BillingController
  // ::showInvoice parity (reads are audited in the Laravel contract).
  await deps.recordAudit({
    action: 'invoice.viewed',
    resourceType: 'invoice',
    resourceId: invoice.id,
    actorId: user.id,
    tenantId: context.organizationId ?? '',
    facilityId: invoice.facilityId,
    correlationId,
    payload: { patientId: invoice.patientId },
  });

  // The exact BillingController::presentInvoice data shape. Lines arrive
  // ordered by line_no from the RLS-scoped read.
  const data: InvoiceShowPresentation = {
    id: invoice.id,
    invoiceNumber: invoice.invoiceNumber,
    facilityId: invoice.facilityId,
    patientId: invoice.patientId,
    status: invoice.status,
    totalMinor: invoice.totalMinor,
    totalTaxMinor: invoice.totalTaxMinor,
    paidMinor: invoice.paidMinor,
    issuedAt: invoice.issuedAt,
    lockVersion: invoice.lockVersion,
    lines,
  };

  return successEnvelope(
    data,
    correlationId,
    context,
    { claimsIssued: claimsComplete(claims) },
  );
}
