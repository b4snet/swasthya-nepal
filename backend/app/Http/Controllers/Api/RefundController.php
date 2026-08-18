<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RejectRefundRequest;
use App\Http\Requests\Billing\StoreRefundRequest;
use App\Models\Charge;
use App\Models\Notification;
use App\Models\RefundRequest;
use App\Services\BillingService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 slice 5 — the refund/adjustment request surface (PRODUCT_REQUIREMENTS
 * §6.13, DATABASE.md §3.33): a posted charge → refund/adjustment request →
 * authorized approval → immutable reversing entry. The approved request IS
 * the reversal; the original charge is never mutated.
 *
 * AccessCheck::scoped covers tenant + facility scope on both the charge and
 * the request (refund_requests carries tenant_id + facility_id). Segregation
 * of duties: the approver must differ from the requester (enforced in
 * BillingService).
 */
final class RefundController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BillingService $billing,
    ) {}

    /**
     * GET charges/{charge}/refunds — the requests (and approved reversals)
     * against one charge, oldest first.
     */
    public function index(Request $request, Charge $charge): JsonResponse
    {
        AccessCheck::scoped($charge, write: false);

        $requests = $charge->refunds()
            ->orderBy('created_at')
            ->get()
            ->map(fn (RefundRequest $r): array => self::present($r))
            ->values();

        return Envelope::success(data: $requests, request: $request);
    }

    /**
     * POST charges/{charge}/refunds — request a refund/adjustment.
     */
    public function store(StoreRefundRequest $request, Charge $charge): JsonResponse
    {
        AccessCheck::scoped($charge, write: true);

        $context = TenantContext::current();

        $refundRequest = $this->billing->requestRefund(
            (string) $context->tenantId(),
            (string) $charge->facility_id,
            (string) $charge->getKey(),
            (int) $request->validated('amountMinor'),
            (string) $request->validated('reasonCode'),
            $request->validated('reasonNote'),
            $context->user?->getKey(),
        );

        $this->audit->record('refund.requested', 'refund_request', $refundRequest->getKey(), [
            'chargeId' => $charge->getKey(),
            'amountMinor' => $refundRequest->amount_minor,
            'reasonCode' => $refundRequest->reason_code,
        ], $request);

        return Envelope::success(data: self::present($refundRequest), status: 201, request: $request);
    }

    /**
     * POST refund-requests/{refundRequest}/approve — the financial gate.
     */
    public function approve(Request $request, RefundRequest $refundRequest): JsonResponse
    {
        AccessCheck::scoped($refundRequest, write: true);

        $context = TenantContext::current();

        $approved = $this->billing->approveRefund(
            (string) $context->tenantId(),
            (string) $refundRequest->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('refund.approved', 'refund_request', $approved->getKey(), [
            'chargeId' => $approved->charge_id,
            'amountMinor' => $approved->amount_minor,
            'reasonCode' => $approved->reason_code,
        ], $request);

        return Envelope::success(data: self::present($approved), request: $request);
    }

    /**
     * POST refund-requests/{refundRequest}/complete — the approved refund's
     * money has been disbursed back to the patient (DATABASE.md §3.33 — the
     * documented 'completed' state). Same financial gate as approval; the
     * requester can never complete their own refund (segregation of duties).
     */
    public function complete(Request $request, RefundRequest $refundRequest): JsonResponse
    {
        AccessCheck::scoped($refundRequest, write: true);

        $context = TenantContext::current();

        $completed = $this->billing->completeRefund(
            (string) $context->tenantId(),
            (string) $refundRequest->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('refund.completed', 'refund_request', $completed->getKey(), [
            'chargeId' => $completed->charge_id,
            'amountMinor' => $completed->amount_minor,
            'reasonCode' => $completed->reason_code,
        ], $request);

        return Envelope::success(data: self::present($completed), request: $request);
    }

    /**
     * POST refund-requests/{refundRequest}/reject — approver declines.
     */
    public function reject(RejectRefundRequest $request, RefundRequest $refundRequest): JsonResponse
    {
        AccessCheck::scoped($refundRequest, write: true);

        $context = TenantContext::current();

        $rejected = $this->billing->rejectRefund(
            (string) $context->tenantId(),
            (string) $refundRequest->getKey(),
            (string) $request->validated('rejectionReason'),
            $context->user?->getKey(),
        );

        $this->audit->record('refund.rejected', 'refund_request', $rejected->getKey(), [
            'chargeId' => $rejected->charge_id,
            'amountMinor' => $rejected->amount_minor,
            'reasonCode' => $rejected->reason_code,
        ], $request);

        return Envelope::success(data: self::present($rejected), request: $request);
    }

    /**
     * GET refund-requests/{refundRequest}/notification — the billing team's
     * in-app view of the return's billing notification (PRODUCT_REQUIREMENTS
     * §5.4: module owners trigger domain notifications; DATABASE.md §3.37).
     * Created atomically with the pharmacy return that opened this request;
     * a manual refund request (no return) has none → 404. Read-only: no
     * mutation, no audit.
     */
    public function notification(Request $request, RefundRequest $refundRequest): JsonResponse
    {
        AccessCheck::scoped($refundRequest, write: false);

        $notification = Notification::query()
            ->where('tenant_id', $refundRequest->tenant_id)
            ->where('refund_request_id', $refundRequest->getKey())
            ->first();

        if ($notification === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'No billing notification has been created for this refund request.', 404);
        }

        return Envelope::success(data: self::presentNotification($notification), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentNotification(Notification $notification): array
    {
        return [
            'id' => $notification->getKey(),
            'refundRequestId' => $notification->refund_request_id,
            'patientId' => $notification->patient_id,
            'type' => $notification->type,
            'channel' => $notification->channel,
            'status' => $notification->status,
            'sensitive' => $notification->sensitive,
            'payload' => $notification->payload,
            'createdAt' => $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(RefundRequest $request): array
    {
        return [
            'id' => $request->getKey(),
            'chargeId' => $request->charge_id,
            'amountMinor' => $request->amount_minor,
            'reasonCode' => $request->reason_code,
            'status' => $request->status,
            'requestedBy' => $request->requested_by,
            'approvedBy' => $request->approved_by,
            'approvedAt' => $request->approved_at?->toIso8601String(),
            'rejectedBy' => $request->rejected_by,
            'rejectedAt' => $request->rejected_at?->toIso8601String(),
            'completedBy' => $request->completed_by,
            'completedAt' => $request->completed_at?->toIso8601String(),
            'lockVersion' => $request->lock_version,
            'createdAt' => $request->created_at?->toIso8601String(),
        ];
    }
}
