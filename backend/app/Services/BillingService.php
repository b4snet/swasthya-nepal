<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Charge;
use App\Models\DepositAllocation;
use App\Models\HospitalBranding;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
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
     * Void a posted charge (ROADMAP §14, DATABASE.md §3.33). Void is a
     * status with reason and approver — the charge row is never deleted and
     * its financial fields are never mutated. A charge can only be voided
     * while POSTED and before value has attached to it:
     *
     *  - an invoiced charge is refused (the bill was built from it — void
     *    the invoice instead, which cascades);
     *  - a charge with a pending, approved, or completed refund is refused
     *    (money is reserved against it — the refund path is the correction).
     *
     * Charges carry no lock_version, so the CAS is on status alone: under
     * the row lock two concurrent voids resolve to exactly one — the loser
     * affects zero rows and gets a 409.
     */
    public function voidCharge(
        string $tenantId,
        string $chargeId,
        string $reason,
        ?string $voidedBy = null,
    ): Charge {
        return DB::transaction(function () use ($tenantId, $chargeId, $reason, $voidedBy): Charge {
            // Lock the charge row: concurrent voids serialize here.
            $charge = Charge::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $chargeId)
                ->lockForUpdate()
                ->first();

            if ($charge === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Charge not found.', 404);
            }

            if ($charge->status !== Charge::STATUS_POSTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a posted charge can be voided.', 409);
            }

            $invoiced = InvoiceLine::query()
                ->where('tenant_id', $tenantId)
                ->where('charge_id', $chargeId)
                ->exists();

            if ($invoiced) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This charge has already been invoiced; void the invoice instead.', 409);
            }

            $hasReservedRefunds = RefundRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('charge_id', $chargeId)
                ->whereIn('status', [
                    RefundRequest::STATUS_REQUESTED,
                    RefundRequest::STATUS_APPROVED,
                    RefundRequest::STATUS_COMPLETED,
                ])
                ->exists();

            if ($hasReservedRefunds) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A refund is pending or approved against this charge; it cannot be voided.', 409);
            }

            // CAS on status: a stale concurrent voider affects zero rows.
            $affected = DB::table('charges')
                ->where('tenant_id', $tenantId)
                ->where('id', $chargeId)
                ->where('status', Charge::STATUS_POSTED)
                ->update([
                    'status' => Charge::STATUS_VOIDED,
                    'voided_by' => $voidedBy,
                    'void_reason' => $reason,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This charge was voided by another actor. Reload and retry.', 409);
            }

            return $charge->refresh();
        });
    }

    /**
     * Void an uncollected invoice (ROADMAP §14, DATABASE.md §3.33). Only a
     * draft or issued invoice with NO value attached can be voided:
     * payments, deposit allocations, and insurance claims all refuse void
     * (money or value moved — the refund/credit path is the correction).
     *
     * The void CASCADES to the invoice's charges — the bill and the charges
     * it was built from are cancelled together in one atomic transaction
     * (the same reason and approver); a re-bill is a fresh charge (a charge
     * appears on at most one invoice).
     *
     * CAS on (status, lock_version): a stale concurrent voider affects zero
     * rows and gets a 409.
     *
     * @return array{invoice: Invoice, voidedChargeCount: int}
     */
    public function voidInvoice(
        string $tenantId,
        string $invoiceId,
        string $reason,
        ?string $voidedBy = null,
    ): array {
        return DB::transaction(function () use ($tenantId, $invoiceId, $reason, $voidedBy): array {
            // Lock the invoice row: concurrent voids serialize here.
            $invoice = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Invoice not found.', 404);
            }

            if ($invoice->status === Invoice::STATUS_VOIDED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This invoice is already voided.', 409);
            }

            if (! in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an uncollected invoice can be voided; money has moved — use the refund path.', 409);
            }

            $hasPayments = PaymentAllocation::query()
                ->where('tenant_id', $tenantId)
                ->where('invoice_id', $invoiceId)
                ->exists();

            if ($hasPayments) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Payments have been captured against this invoice; it cannot be voided.', 409);
            }

            $hasDepositAllocations = DepositAllocation::query()
                ->where('tenant_id', $tenantId)
                ->where('invoice_id', $invoiceId)
                ->exists();

            if ($hasDepositAllocations) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Deposits have been allocated to this invoice; it cannot be voided.', 409);
            }

            $hasClaims = InsuranceClaim::query()
                ->where('tenant_id', $tenantId)
                ->where('invoice_id', $invoiceId)
                ->exists();

            if ($hasClaims) {
                throw new ApiException(ErrorCodes::CONFLICT, 'An insurance claim has been built from this invoice; it cannot be voided.', 409);
            }

            // CAS on the invoice row (status + lock_version).
            $affected = DB::table('invoices')
                ->where('tenant_id', $tenantId)
                ->where('id', $invoiceId)
                ->where('status', $invoice->status)
                ->where('lock_version', $invoice->lock_version)
                ->update([
                    'status' => Invoice::STATUS_VOIDED,
                    'void_reason' => $reason,
                    'updated_by' => $voidedBy,
                    'lock_version' => $invoice->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This invoice was changed by another actor. Reload and retry.', 409);
            }

            // Cascade: void the charges the invoice was built from (they can
            // never be re-invoiced — one invoice per charge — so the whole
            // erroneous bill is cancelled together). The charges are all
            // POSTED by construction (issueInvoice only takes posted
            // charges); the status CAS keeps the cascade idempotent.
            $chargeIds = InvoiceLine::query()
                ->where('tenant_id', $tenantId)
                ->where('invoice_id', $invoiceId)
                ->pluck('charge_id');

            $voidedChargeCount = 0;
            if ($chargeIds->isNotEmpty()) {
                $voidedChargeCount = DB::table('charges')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $chargeIds)
                    ->where('status', Charge::STATUS_POSTED)
                    ->update([
                        'status' => Charge::STATUS_VOIDED,
                        'voided_by' => $voidedBy,
                        'void_reason' => $reason,
                        'updated_at' => now(),
                    ]);
            }

            return [
                'invoice' => $invoice->refresh(),
                'voidedChargeCount' => $voidedChargeCount,
            ];
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

    /**
     * Generate a receipt for a completed payment. The receipt is an immutable
     * document capturing the payment details, hospital branding snapshot,
     * and line items from the invoice.
     */
    public function generateReceipt(
        string $tenantId,
        string $paymentId,
        ?string $issuedBy = null,
    ): Receipt {
        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $paymentId)
            ->first();

        if ($payment === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Payment not found.', 404);
        }

        // Check if receipt already exists for this payment
        $existing = Receipt::query()
            ->where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Get the allocation to find the invoice
        $allocation = PaymentAllocation::query()
            ->where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->first();

        if ($allocation === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'No allocation found for this payment.', 404);
        }

        $invoice = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $allocation->invoice_id)
            ->first();

        // Build line items from the invoice
        $items = $invoice !== null
            ? $invoice->lines()->orderBy('line_no')->get()->map(fn ($line): array => [
                'description' => $line->description,
                'amountMinor' => $line->amount_minor,
                'taxMinor' => $line->tax_minor,
            ])->values()->all()
            : [];

        // Capture branding snapshot
        $branding = HospitalBranding::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $payment->facility_id)
            ->first();

        $brandingSnapshot = $branding?->present() ?? [
            'hospitalName' => null,
            'currency' => 'NPR',
            'currencySymbol' => 'Rs.',
        ];

        return Receipt::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $payment->facility_id,
            'payment_id' => $paymentId,
            'invoice_id' => $allocation->invoice_id,
            'patient_id' => $payment->patient_id,
            'receipt_number' => $this->nextReceiptNumber($tenantId),
            'status' => Receipt::STATUS_ISSUED,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency ?? 'NPR',
            'method' => $payment->method,
            'payment_method_label' => ucfirst(str_replace('_', ' ', $payment->method)),
            'items' => $items,
            'branding_snapshot' => $brandingSnapshot,
            'issued_by' => $issuedBy,
        ]);
    }

    private function nextNumber(string $tenantId): string
    {
        do {
            $number = 'INV-'.date('Ymd').'-'.random_int(10000, 99999);
        } while (Invoice::query()->where('tenant_id', $tenantId)->where('invoice_number', $number)->exists());

        return $number;
    }

    private function nextReceiptNumber(string $tenantId): string
    {
        do {
            $number = 'RCP-'.date('Ymd').'-'.random_int(10000, 99999);
        } while (Receipt::query()->where('tenant_id', $tenantId)->where('receipt_number', $number)->exists());

        return $number;
    }
}
