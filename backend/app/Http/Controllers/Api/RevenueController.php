<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingAdjustment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Receipt;
use App\Services\BillingAdjustmentService;
use App\Services\BillingService;
use App\Services\RevenueReportService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 85 — complete revenue cycle: financial reports, billing
 * adjustments, and receipt management.
 */
final class RevenueController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevenueReportService $reports,
        private readonly BillingService $billing,
        private readonly BillingAdjustmentService $adjustments,
    ) {}

    // ── Reports ──────────────────────────────────────────────────

    /**
     * GET /organizations/{org}/revenue/summary — revenue summary for a facility.
     */
    public function revenueSummary(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $validated = $request->validate([
            'facilityId' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : now()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now()->endOfMonth();

        $data = $this->reports->revenueSummary(
            (string) $organization->getKey(),
            $validated['facilityId'],
            $from,
            $to,
        );

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * GET /organizations/{org}/revenue/by-source — revenue breakdown by source.
     */
    public function revenueBySource(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $validated = $request->validate([
            'facilityId' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : now()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now()->endOfMonth();

        $data = $this->reports->revenueBySource(
            (string) $organization->getKey(),
            $validated['facilityId'],
            $from,
            $to,
        );

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * GET /organizations/{org}/revenue/daily-trend — daily revenue trend.
     */
    public function dailyTrend(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $validated = $request->validate([
            'facilityId' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : now()->subDays(30);
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now();

        // Limit to 90 days max
        if ($from->diffInDays($to) > 90) {
            $from = $to->copy()->subDays(90);
        }

        $data = $this->reports->dailyTrend(
            (string) $organization->getKey(),
            $validated['facilityId'],
            $from,
            $to,
        );

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * GET /organizations/{org}/revenue/expense-summary — expense summary.
     */
    public function expenseSummary(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $validated = $request->validate([
            'facilityId' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : now()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now()->endOfMonth();

        $data = $this->reports->expenseSummary(
            (string) $organization->getKey(),
            $validated['facilityId'],
            $from,
            $to,
        );

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * GET /organizations/{org}/revenue/aging — patient account aging.
     */
    public function agingAnalysis(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $validated = $request->validate([
            'facilityId' => ['required', 'string'],
        ]);

        $data = $this->reports->agingAnalysis(
            (string) $organization->getKey(),
            $validated['facilityId'],
        );

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * GET /budgets/{budget}/vs-actual — budget vs actual.
     */
    public function budgetVsActual(Request $request, string $budget): JsonResponse
    {
        $context = TenantContext::current();

        $data = $this->reports->budgetVsActual(
            (string) $context->tenantId(),
            $budget,
        );

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * GET /financial-periods/{period}/summary — period financial summary.
     */
    public function periodSummary(Request $request, string $period): JsonResponse
    {
        $context = TenantContext::current();

        $data = $this->reports->periodSummary(
            (string) $context->tenantId(),
            $period,
        );

        return Envelope::success(data: $data, request: $request);
    }

    // ── Receipts ─────────────────────────────────────────────────

    /**
     * GET /payments/{payment}/receipt — get receipt for a payment.
     */
    public function receipt(Request $request, string $payment): JsonResponse
    {
        $context = TenantContext::current();

        $receipt = Receipt::query()
            ->where('tenant_id', $context->tenantId())
            ->where('payment_id', $payment)
            ->first();

        if ($receipt === null) {
            return Envelope::success(data: null, request: $request);
        }

        return Envelope::success(data: $receipt->present(), request: $request);
    }

    /**
     * POST /payments/{payment}/receipt — generate receipt for a payment.
     */
    public function generateReceipt(Request $request, string $payment): JsonResponse
    {
        $context = TenantContext::current();

        $receipt = $this->billing->generateReceipt(
            (string) $context->tenantId(),
            $payment,
            $context->user?->getKey(),
        );

        $this->audit->record('receipt.generated', 'receipt', $receipt->getKey(), [
            'receiptNumber' => $receipt->receipt_number,
            'amountMinor' => $receipt->amount_minor,
        ], $request);

        return Envelope::success(data: $receipt->present(), status: 201, request: $request);
    }

    /**
     * POST /receipts/{receipt}/print — mark receipt as printed.
     */
    public function printReceipt(Request $request, Receipt $receipt): JsonResponse
    {
        AccessCheck::scoped($receipt, write: true);

        $receipt->update([
            'printed' => true,
            'printed_at' => now(),
            'status' => Receipt::STATUS_PRINTED,
        ]);

        return Envelope::success(data: $receipt->fresh()->present(), request: $request);
    }

    // ── Adjustments ──────────────────────────────────────────────

    /**
     * GET /invoices/{invoice}/adjustments — list adjustments for an invoice.
     */
    public function adjustments(Request $request, Invoice $invoice): JsonResponse
    {
        AccessCheck::scoped($invoice, write: false);

        $adjustments = BillingAdjustment::query()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('invoice_id', $invoice->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BillingAdjustment $a): array => $a->present())
            ->values();

        return Envelope::success(data: $adjustments, request: $request);
    }

    /**
     * POST /invoices/{invoice}/adjustments — request a billing adjustment.
     */
    public function requestAdjustment(Request $request, Invoice $invoice): JsonResponse
    {
        AccessCheck::scoped($invoice, write: true);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:credit,debit'],
            'amountMinor' => ['required', 'integer', 'min:1'],
            'reasonCode' => ['required', 'string'],
            'reasonNote' => ['nullable', 'string'],
        ]);

        $context = TenantContext::current();

        $adjustment = $this->adjustments->requestAdjustment(
            (string) $context->tenantId(),
            (string) $invoice->facility_id,
            (string) $invoice->getKey(),
            (string) $invoice->patient_id,
            $validated['type'],
            $validated['amountMinor'],
            $validated['reasonCode'],
            $validated['reasonNote'] ?? null,
            $context->user?->getKey(),
        );

        $this->audit->record('adjustment.requested', 'billing_adjustment', $adjustment->getKey(), [
            'adjustmentNumber' => $adjustment->adjustment_number,
            'type' => $adjustment->type,
            'amountMinor' => $adjustment->amount_minor,
        ], $request);

        return Envelope::success(data: $adjustment->present(), status: 201, request: $request);
    }

    /**
     * POST /billing-adjustments/{adjustment}/approve — approve an adjustment.
     */
    public function approveAdjustment(Request $request, BillingAdjustment $adjustment): JsonResponse
    {
        AccessCheck::scoped($adjustment, write: true);

        $context = TenantContext::current();

        $approved = $this->adjustments->approveAdjustment(
            (string) $context->tenantId(),
            (string) $adjustment->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('adjustment.approved', 'billing_adjustment', $approved->getKey(), [
            'adjustmentNumber' => $approved->adjustment_number,
        ], $request);

        return Envelope::success(data: $approved->present(), request: $request);
    }

    /**
     * POST /billing-adjustments/{adjustment}/apply — apply an approved adjustment.
     */
    public function applyAdjustment(Request $request, BillingAdjustment $adjustment): JsonResponse
    {
        AccessCheck::scoped($adjustment, write: true);

        $context = TenantContext::current();

        $applied = $this->adjustments->applyAdjustment(
            (string) $context->tenantId(),
            (string) $adjustment->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('adjustment.applied', 'billing_adjustment', $applied->getKey(), [
            'adjustmentNumber' => $applied->adjustment_number,
            'type' => $applied->type,
            'amountMinor' => $applied->amount_minor,
        ], $request);

        return Envelope::success(data: $applied->present(), request: $request);
    }

    /**
     * POST /billing-adjustments/{adjustment}/reject — reject a pending adjustment.
     */
    public function rejectAdjustment(Request $request, BillingAdjustment $adjustment): JsonResponse
    {
        AccessCheck::scoped($adjustment, write: true);

        $validated = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $context = TenantContext::current();

        $rejected = $this->adjustments->rejectAdjustment(
            (string) $context->tenantId(),
            (string) $adjustment->getKey(),
            $validated['reason'],
            $context->user?->getKey(),
        );

        $this->audit->record('adjustment.rejected', 'billing_adjustment', $rejected->getKey(), [
            'adjustmentNumber' => $rejected->adjustment_number,
        ], $request);

        return Envelope::success(data: $rejected->present(), request: $request);
    }
}
