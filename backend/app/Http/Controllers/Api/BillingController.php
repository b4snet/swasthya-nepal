<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CapturePaymentRequest;
use App\Http\Requests\Billing\VoidChargeRequest;
use App\Http\Requests\Billing\VoidInvoiceRequest;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BillingService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient-facing financial surface (DATABASE.md §3.33–3.34): invoice
 * view and payment capture. Payments are idempotent per key — retries never
 * double-charge.
 */
final class BillingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BillingService $billing,
    ) {}

    public function showInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        AccessCheck::scoped($invoice, write: false);

        $this->audit->record('invoice.viewed', 'invoice', $invoice->getKey(), ['patientId' => $invoice->patient_id], $request);

        return Envelope::success(data: self::presentInvoice($invoice), request: $request);
    }

    public function payments(Request $request, Invoice $invoice): JsonResponse
    {
        AccessCheck::scoped($invoice, write: false);

        $payments = $invoice->allocations()
            ->with('payment:id,method,provider_ref,amount_minor,received_at')
            ->orderBy('allocated_at')
            ->get()
            ->map(fn ($allocation): array => [
                'paymentId' => $allocation->payment_id,
                'method' => $allocation->payment?->method,
                'amountMinor' => $allocation->amount_minor,
                'allocatedAt' => $allocation->allocated_at?->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $payments, request: $request);
    }

    /**
     * POST /invoices/{invoice}/pay — capture and allocate.
     */
    public function pay(CapturePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        AccessCheck::scoped($invoice, write: true);

        $context = TenantContext::current();

        $payment = $this->billing->capturePayment(
            (string) $context->tenantId(),
            (string) $invoice->facility_id,
            (string) $invoice->patient_id,
            (string) $invoice->getKey(),
            (string) $request->validated('method'),
            (int) $request->validated('amountMinor'),
            (string) $request->validated('idempotencyKey'),
            $request->validated('providerRef'),
            $context->user?->getKey(),
        );

        $isNew = $payment->wasRecentlyCreated;

        $this->audit->record(
            $isNew ? 'payment.captured' : 'payment.replayed',
            'payment',
            $payment->getKey(),
            ['invoiceId' => $invoice->getKey(), 'method' => $payment->method, 'amountMinor' => $payment->amount_minor, 'replayed' => ! $isNew],
            $request,
        );

        $invoice->refresh();

        return Envelope::success(
            data: [
                'paymentId' => $payment->getKey(),
                'status' => $payment->status,
                'amountMinor' => $payment->amount_minor,
                'method' => $payment->method,
                'replayed' => ! $isNew,
                'invoice' => [
                    'id' => $invoice->getKey(),
                    'invoiceNumber' => $invoice->invoice_number,
                    'status' => $invoice->status,
                    'totalMinor' => $invoice->total_minor,
                    'paidMinor' => $invoice->paid_minor,
                ],
            ],
            status: $isNew ? 201 : 200,
            request: $request,
        );
    }

    /**
     * POST charges/{charge}/void — void a posted charge (ROADMAP §14,
     * DATABASE.md §3.33): status + required reason + approver, never a
     * delete. Restricted to billing:void — the clerk who charges cannot
     * void (segregation of duties). The reason is free text that may
     * contain PHI and is therefore recorded on the row but never echoed in
     * the response or any audit payload.
     */
    public function voidCharge(VoidChargeRequest $request, Charge $charge): JsonResponse
    {
        AccessCheck::scoped($charge, write: true);

        $context = TenantContext::current();

        $voided = $this->billing->voidCharge(
            (string) $context->tenantId(),
            (string) $charge->getKey(),
            (string) $request->validated('reason'),
            $context->user?->getKey(),
        );

        $this->audit->record('charge.voided', 'charge', $voided->getKey(), [
            'amountMinor' => $voided->amount_minor,
            'currency' => $voided->currency,
            'sourceType' => $voided->source_type,
        ], $request);

        return Envelope::success(data: [
            'id' => $voided->getKey(),
            'sourceType' => $voided->source_type,
            'amountMinor' => $voided->amount_minor,
            'currency' => $voided->currency,
            'status' => $voided->status,
            'voidedBy' => $voided->voided_by,
            'chargedAt' => $voided->charged_at?->toIso8601String(),
        ], request: $request);
    }

    /**
     * POST invoices/{invoice}/void — void an uncollected draft/issued
     * invoice (ROADMAP §14, DATABASE.md §3.33). Payments, deposit
     * allocations, and insurance claims all refuse void (money or value
     * moved — the refund/credit path is the correction). The void cascades
     * to the charges the invoice was built from in one atomic transaction.
     * Reason + approver required; restricted to billing:void.
     */
    public function voidInvoice(VoidInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        AccessCheck::scoped($invoice, write: true);

        $context = TenantContext::current();

        $result = $this->billing->voidInvoice(
            (string) $context->tenantId(),
            (string) $invoice->getKey(),
            (string) $request->validated('reason'),
            $context->user?->getKey(),
        );

        $voided = $result['invoice'];

        $this->audit->record('invoice.voided', 'invoice', $voided->getKey(), [
            'invoiceNumber' => $voided->invoice_number,
            'totalMinor' => $voided->total_minor,
            'voidedChargeCount' => $result['voidedChargeCount'],
        ], $request);

        return Envelope::success(data: [
            'id' => $voided->getKey(),
            'invoiceNumber' => $voided->invoice_number,
            'status' => $voided->status,
            'totalMinor' => $voided->total_minor,
            'voidedChargeCount' => $result['voidedChargeCount'],
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getKey(),
            'invoiceNumber' => $invoice->invoice_number,
            'facilityId' => $invoice->facility_id,
            'patientId' => $invoice->patient_id,
            'status' => $invoice->status,
            'totalMinor' => $invoice->total_minor,
            'totalTaxMinor' => $invoice->total_tax_minor,
            'paidMinor' => $invoice->paid_minor,
            'issuedAt' => $invoice->issued_at?->toIso8601String(),
            'lockVersion' => $invoice->lock_version,
            'lines' => $invoice->lines()->orderBy('line_no')->get()->map(fn ($line): array => [
                'id' => $line->getKey(),
                'description' => $line->description,
                'amountMinor' => $line->amount_minor,
                'taxMinor' => $line->tax_minor,
            ])->values(),
        ];
    }
}
