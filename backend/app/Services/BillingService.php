<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
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

    private function nextNumber(string $tenantId): string
    {
        do {
            $number = 'INV-'.date('Ymd').'-'.random_int(10000, 99999);
        } while (Invoice::query()->where('tenant_id', $tenantId)->where('invoice_number', $number)->exists());

        return $number;
    }
}
