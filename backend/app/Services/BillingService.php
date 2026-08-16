<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RefundRequest;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;

/**
 * Billing operations (DATABASE.md §3.33–3.34): issuing an invoice from
 * posted charges and capturing a payment against it.
 *
 *  - Invoice issue is atomic and idempotent per charge: a charge can appear
 *    on at most one invoice (partial unique index) — a retry cannot
 *    double-bill.
 *  - Payment capture is idempotent per idempotency key (unique index) and
 *    allocation is guarded by invoice lock_version — concurrent payments
 *    against one invoice resolve to sequential updates, never lost money.
 *  - Money is integer minor units end to end (DATABASE.md §0.4).
 */
final class BillingService
{
    public function issueInvoice(string $tenantId, string $facilityId, string $patientId, array $chargeIds, ?string $createdBy = null): Invoice
    {
        return DB::transaction(function () use ($tenantId, $facilityId, $patientId, $chargeIds, $createdBy): Invoice {
            if ($chargeIds === []) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'At least one charge is required to issue an invoice.', 422);
            }

            $charges = Charge::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $chargeIds)
                ->where('status', Charge::STATUS_POSTED)
                ->get();

            if ($charges->count() !== count($chargeIds)) {
                throw new ApiException(ErrorCodes::INVALID_REQUEST, 'One or more charges are missing or already voided.', 422);
            }

            // A charge can be invoiced at most once (partial unique index) —
            // check BEFORE inserting so the retry is a clean 422, not a
            // raw unique-violation 500.
            $alreadyInvoiced = InvoiceLine::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('charge_id', $chargeIds)
                ->exists();

            if ($alreadyInvoiced) {
                throw new ApiException(ErrorCodes::CONFLICT, 'One or more charges have already been invoiced.', 409);
            }

            $invoice = Invoice::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $patientId,
                'invoice_number' => $this->nextNumber($tenantId),
                'status' => Invoice::STATUS_ISSUED,
                'total_minor' => $charges->sum('amount_minor'),
                'total_tax_minor' => $charges->sum(fn (Charge $c): int => (int) round($c->amount_minor * $c->tax_rate_bps / 10000)),
                'paid_minor' => 0,
                'issued_at' => now(),
                'created_by' => $createdBy,
                'lock_version' => 0,
            ]);

            $lineNo = 1;
            foreach ($charges as $charge) {
                InvoiceLine::query()->create([
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->getKey(),
                    'charge_id' => $charge->getKey(),
                    'description' => $charge->description,
                    'amount_minor' => $charge->amount_minor,
                    'tax_minor' => (int) round($charge->amount_minor * $charge->tax_rate_bps / 10000),
                    'line_no' => $lineNo++,
                ]);
            }

            return $invoice;
        });
    }

    /**
     * Capture a payment and allocate it against an invoice. Returns the
     * payment. Idempotent per idempotency key: a retry with the same key
     * returns the existing payment and applies no new money.
     */
    public function capturePayment(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $invoiceId,
        string $method,
        int $amountMinor,
        string $idempotencyKey,
        ?string $providerRef = null,
        ?string $receivedBy = null,
    ): Payment {
        return DB::transaction(function () use (
            $tenantId, $facilityId, $patientId, $invoiceId, $method,
            $amountMinor, $idempotencyKey, $providerRef, $receivedBy,
        ): Payment {
            // Idempotency: same key → the same payment, no new money.
            $existing = Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $invoice = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $invoiceId)
                ->first();

            if ($invoice === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Invoice not found.', 404);
            }

            if ($invoice->status === Invoice::STATUS_VOIDED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A voided invoice cannot be paid.', 409);
            }

            if ($invoice->paid_minor >= $invoice->total_minor) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This invoice is already paid.', 409);
            }

            $remaining = $invoice->total_minor - $invoice->paid_minor;

            if ($amountMinor <= 0) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Payment amount must be positive.', 422);
            }

            if ($amountMinor > $remaining) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    sprintf('Payment of %d exceeds the outstanding balance of %d.', $amountMinor, $remaining),
                    422,
                );
            }

            $payment = Payment::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $patientId,
                'method' => $method,
                'provider_ref' => $providerRef,
                'amount_minor' => $amountMinor,
                'currency' => 'NPR',
                'status' => Payment::STATUS_CAPTURED,
                'idempotency_key' => $idempotencyKey,
                'received_by' => $receivedBy,
                'received_at' => now(),
                'created_by' => $receivedBy,
            ]);

            PaymentAllocation::query()->create([
                'tenant_id' => $tenantId,
                'payment_id' => $payment->getKey(),
                'invoice_id' => $invoice->getKey(),
                'amount_minor' => $amountMinor,
                'allocated_at' => now(),
                'created_by' => $receivedBy,
            ]);

            // Optimistic lock on the invoice: concurrent captures serialize
            // here — one wins, the loser sees a stale lock_version.
            $newPaid = $invoice->paid_minor + $amountMinor;
            $newStatus = $newPaid >= $invoice->total_minor ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID;

            $affected = DB::table('invoices')
                ->where('id', $invoice->getKey())
                ->where('lock_version', $invoice->lock_version)
                ->update([
                    'paid_minor' => $newPaid,
                    'status' => $newStatus,
                    'lock_version' => $invoice->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This invoice was changed by another payment. Reload and retry.', 409);
            }

            return $payment;
        });
    }

    /**
     * Request a refund/adjustment against a posted charge. The request does
     * not move money — approval does. The refundable amount is
     * `amount_minor − Σ(approved)` for the charge; a request beyond it is
     * refused here, and again under the charge-row lock at approval, so
     * over-refund is impossible even under concurrency.
     */
    public function requestRefund(
        string $tenantId,
        string $facilityId,
        string $chargeId,
        int $amountMinor,
        string $reasonCode,
        ?string $reasonNote,
        ?string $requestedBy = null,
    ): RefundRequest {
        return DB::transaction(function () use ($tenantId, $facilityId, $chargeId, $amountMinor, $reasonCode, $reasonNote, $requestedBy): RefundRequest {
            // Lock the charge row: concurrent requests serialize here.
            $charge = Charge::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $chargeId)
                ->lockForUpdate()
                ->first();

            if ($charge === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Charge not found.', 404);
            }

            if ($charge->status !== Charge::STATUS_POSTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a posted charge can be refunded.', 409);
            }

            $refundable = $charge->amount_minor - $this->approvedTotal($tenantId, $chargeId);

            if ($amountMinor > $refundable) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    sprintf('Refund of %d exceeds the refundable amount of %d.', $amountMinor, $refundable),
                    422,
                );
            }

            return RefundRequest::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $charge->patient_id,
                'charge_id' => $chargeId,
                'amount_minor' => $amountMinor,
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
                'status' => RefundRequest::STATUS_REQUESTED,
                'requested_by' => $requestedBy,
                'lock_version' => 0,
                'created_by' => $requestedBy,
            ]);
        });
    }

    /**
     * Approve a pending refund request. This is the financial gate: the
     * approved request IS the immutable reversing entry — the charge is
     * never mutated. Duplicate approval is impossible (CAS on status +
     * lock_version) and the refundable check runs under the charge-row lock,
     * so concurrent approvals of different requests on one charge can never
     * over-refund.
     */
    public function approveRefund(string $tenantId, string $requestId, ?string $approverId = null): RefundRequest
    {
        return DB::transaction(function () use ($tenantId, $requestId, $approverId): RefundRequest {
            $request = RefundRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $requestId)
                ->first();

            if ($request === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Refund request not found.', 404);
            }

            if ($request->status !== RefundRequest::STATUS_REQUESTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a pending refund request can be approved.', 409);
            }

            if ($approverId !== null && $request->requested_by === $approverId) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot approve their own refund request.', 403);
            }

            // Lock the charge row: concurrent approvals of DIFFERENT requests
            // on the same charge serialize here — the second sees the first's
            // approved total and cannot exceed the refundable amount.
            $charge = Charge::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $request->charge_id)
                ->lockForUpdate()
                ->first();

            if ($charge === null || $charge->status !== Charge::STATUS_POSTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The charge is no longer refundable.', 409);
            }

            $refundable = $charge->amount_minor - $this->approvedTotal($tenantId, $request->charge_id);

            if ($request->amount_minor > $refundable) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    sprintf('Refund of %d exceeds the refundable amount of %d.', $request->amount_minor, $refundable),
                    422,
                );
            }

            // CAS on the request row: a stale approver (same status +
            // lock_version snapshot) affects zero rows and gets a 409.
            $affected = DB::table('refund_requests')
                ->where('id', $requestId)
                ->where('status', RefundRequest::STATUS_REQUESTED)
                ->where('lock_version', $request->lock_version)
                ->update([
                    'status' => RefundRequest::STATUS_APPROVED,
                    'approved_by' => $approverId,
                    'approved_at' => now(),
                    'lock_version' => $request->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This refund request was changed by another approval. Reload and retry.', 409);
            }

            return $request->refresh();
        });
    }

    /**
     * Complete an approved refund request: the money has actually been
     * disbursed back to the patient (DATABASE.md §3.33 — the documented
     * 'completed' state). No payment provider exists or is invented; the
     * finance officer who hands the money over records it here.
     *
     * The approved request remains the immutable reversing entry — the
     * charge is never mutated and the refundable accounting is unchanged
     * (the amount was already reserved at approval). Completion is
     * CAS-guarded (status + lock_version) so a stale or duplicate
     * completion affects zero rows and gets a 409 — a refund can be
     * disbursed exactly once. Segregation of duties: the requester can
     * never complete their own refund (mirrors approval).
     */
    public function completeRefund(string $tenantId, string $requestId, ?string $completerId = null): RefundRequest
    {
        return DB::transaction(function () use ($tenantId, $requestId, $completerId): RefundRequest {
            $request = RefundRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $requestId)
                ->first();

            if ($request === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Refund request not found.', 404);
            }

            if ($request->status !== RefundRequest::STATUS_APPROVED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an approved refund request can be completed.', 409);
            }

            if ($completerId !== null && $request->requested_by === $completerId) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot complete their own refund request.', 403);
            }

            // CAS on the request row: a stale completer (same status +
            // lock_version snapshot) affects zero rows and gets a 409 — the
            // duplicate-disbursement backstop.
            $affected = DB::table('refund_requests')
                ->where('id', $requestId)
                ->where('status', RefundRequest::STATUS_APPROVED)
                ->where('lock_version', $request->lock_version)
                ->update([
                    'status' => RefundRequest::STATUS_COMPLETED,
                    'completed_by' => $completerId,
                    'completed_at' => now(),
                    'lock_version' => $request->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This refund request was changed by another completion. Reload and retry.', 409);
            }

            return $request->refresh();
        });
    }

    /**
     * Reject a pending refund request (approver declines). CAS-guarded like
     * approval; rejection is terminal.
     */
    public function rejectRefund(string $tenantId, string $requestId, string $rejectionReason, ?string $rejectedBy = null): RefundRequest
    {
        return DB::transaction(function () use ($tenantId, $requestId, $rejectionReason, $rejectedBy): RefundRequest {
            $request = RefundRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $requestId)
                ->first();

            if ($request === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Refund request not found.', 404);
            }

            if ($request->status !== RefundRequest::STATUS_REQUESTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a pending refund request can be rejected.', 409);
            }

            if ($rejectedBy !== null && $request->requested_by === $rejectedBy) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot reject their own refund request.', 403);
            }

            $affected = DB::table('refund_requests')
                ->where('id', $requestId)
                ->where('status', RefundRequest::STATUS_REQUESTED)
                ->where('lock_version', $request->lock_version)
                ->update([
                    'status' => RefundRequest::STATUS_REJECTED,
                    'rejected_by' => $rejectedBy,
                    'rejection_reason' => $rejectionReason,
                    'rejected_at' => now(),
                    'lock_version' => $request->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This refund request was changed by another approval. Reload and retry.', 409);
            }

            return $request->refresh();
        });
    }

    /**
     * Total refund/adjustment value reserved against a charge (integer minor
     * units). The refundable amount is amount_minor minus this total.
     *
     * APPROVED and COMPLETED both count: the money was reserved at approval,
     * and completion (Phase 3 slice 11 — the disbursement state) does NOT
     * free it — otherwise a completed refund could be refunded again.
     */
    private function approvedTotal(string $tenantId, string $chargeId): int
    {
        return (int) RefundRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('charge_id', $chargeId)
            ->whereIn('status', [RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_COMPLETED])
            ->sum('amount_minor');
    }

    private function nextNumber(string $tenantId): string
    {
        do {
            $number = 'INV-'.date('Ymd').'-'.random_int(10000, 99999);
        } while (Invoice::query()->where('tenant_id', $tenantId)->where('invoice_number', $number)->exists());

        return $number;
    }
}
