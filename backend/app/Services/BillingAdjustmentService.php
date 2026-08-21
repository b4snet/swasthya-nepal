<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\BillingAdjustment;
use App\Models\Invoice;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;

/**
 * Phase 85 — complete revenue cycle: credit and debit adjustments applied
 * to invoices after issuance. Corrections to posted financial records are
 * adjusting entries, never edits to original data.
 *
 * Lifecycle: pending → approved → applied (or rejected).
 * Segregation: requester ≠ approver ≠ applier.
 * CAS on (status, lock_version) guards all transitions.
 * Money is integer minor units end to end.
 */
final class BillingAdjustmentService
{
    /**
     * Request a billing adjustment against an invoice.
     */
    public function requestAdjustment(
        string $tenantId,
        string $facilityId,
        string $invoiceId,
        string $patientId,
        string $type,
        int $amountMinor,
        string $reasonCode,
        ?string $reasonNote,
        ?string $requestedBy = null,
    ): BillingAdjustment {
        return DB::transaction(function () use (
            $tenantId, $facilityId, $invoiceId, $patientId, $type,
            $amountMinor, $reasonCode, $reasonNote, $requestedBy,
        ): BillingAdjustment {
            if ($amountMinor <= 0) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Adjustment amount must be positive.', 422);
            }

            $invoice = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $invoiceId)
                ->first();

            if ($invoice === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Invoice not found.', 404);
            }

            if ($invoice->status === Invoice::STATUS_VOIDED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Cannot adjust a voided invoice.', 409);
            }

            // Credit adjustments: don't exceed total
            if ($type === BillingAdjustment::TYPE_CREDIT) {
                $existingCredits = BillingAdjustment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('invoice_id', $invoiceId)
                    ->where('type', BillingAdjustment::TYPE_CREDIT)
                    ->whereIn('status', [BillingAdjustment::STATUS_PENDING, BillingAdjustment::STATUS_APPROVED, BillingAdjustment::STATUS_APPLIED])
                    ->sum('amount_minor');

                if ($existingCredits + $amountMinor > $invoice->total_minor) {
                    throw new ApiException(
                        ErrorCodes::VALIDATION_ERROR,
                        sprintf('Credit adjustment of %d would exceed the invoice total of %d.', $amountMinor, $invoice->total_minor),
                        422,
                    );
                }
            }

            return BillingAdjustment::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'invoice_id' => $invoiceId,
                'patient_id' => $patientId,
                'adjustment_number' => $this->nextNumber($tenantId),
                'type' => $type,
                'amount_minor' => $amountMinor,
                'currency' => 'NPR',
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
                'status' => BillingAdjustment::STATUS_PENDING,
                'requested_by' => $requestedBy,
                'requested_at' => now(),
                'lock_version' => 0,
            ]);
        });
    }

    /**
     * Approve a pending adjustment. Segregation: approver ≠ requester.
     */
    public function approveAdjustment(
        string $tenantId,
        string $adjustmentId,
        ?string $approverId = null,
    ): BillingAdjustment {
        return DB::transaction(function () use ($tenantId, $adjustmentId, $approverId): BillingAdjustment {
            $adjustment = BillingAdjustment::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $adjustmentId)
                ->first();

            if ($adjustment === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Billing adjustment not found.', 404);
            }

            if ($adjustment->status !== BillingAdjustment::STATUS_PENDING) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a pending adjustment can be approved.', 409);
            }

            if ($approverId !== null && $adjustment->requested_by === $approverId) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot approve their own adjustment.', 403);
            }

            // Re-verify credit limit under lock
            if ($adjustment->type === BillingAdjustment::TYPE_CREDIT) {
                $invoice = Invoice::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $adjustment->invoice_id)
                    ->lockForUpdate()
                    ->first();

                $existingCredits = BillingAdjustment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('invoice_id', $adjustment->invoice_id)
                    ->where('id', '!=', $adjustmentId)
                    ->where('type', BillingAdjustment::TYPE_CREDIT)
                    ->whereIn('status', [BillingAdjustment::STATUS_APPROVED, BillingAdjustment::STATUS_APPLIED])
                    ->sum('amount_minor');

                if ($existingCredits + $adjustment->amount_minor > $invoice->total_minor) {
                    throw new ApiException(
                        ErrorCodes::VALIDATION_ERROR,
                        'Credit adjustment would now exceed the invoice total.',
                        422,
                    );
                }
            }

            $affected = DB::table('billing_adjustments')
                ->where('id', $adjustmentId)
                ->where('status', BillingAdjustment::STATUS_PENDING)
                ->where('lock_version', $adjustment->lock_version)
                ->update([
                    'status' => BillingAdjustment::STATUS_APPROVED,
                    'approved_by' => $approverId,
                    'approved_at' => now(),
                    'lock_version' => $adjustment->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This adjustment was modified. Reload and retry.', 409);
            }

            return $adjustment->refresh();
        });
    }

    /**
     * Apply an approved adjustment: update the invoice financials.
     * The applier must differ from both requester and approver.
     */
    public function applyAdjustment(
        string $tenantId,
        string $adjustmentId,
        ?string $applierId = null,
    ): BillingAdjustment {
        return DB::transaction(function () use ($tenantId, $adjustmentId, $applierId): BillingAdjustment {
            $adjustment = BillingAdjustment::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $adjustmentId)
                ->first();

            if ($adjustment === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Billing adjustment not found.', 404);
            }

            if ($adjustment->status !== BillingAdjustment::STATUS_APPROVED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an approved adjustment can be applied.', 409);
            }

            if ($applierId !== null && $adjustment->requested_by === $applierId) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot apply their own adjustment.', 403);
            }

            if ($applierId !== null && $adjustment->approved_by === $applierId) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The approver cannot apply the adjustment themselves.', 403);
            }

            $invoice = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $adjustment->invoice_id)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Invoice not found.', 404);
            }

            if ($adjustment->type === BillingAdjustment::TYPE_CREDIT) {
                $newTotal = $invoice->total_minor - $adjustment->amount_minor;
                if ($newTotal < 0) {
                    throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Credit adjustment would make invoice negative.', 422);
                }

                DB::table('invoices')
                    ->where('id', $invoice->getKey())
                    ->update([
                        'total_minor' => $newTotal,
                        'updated_at' => now(),
                    ]);
            }

            // Debit adjustments increase the total
            if ($adjustment->type === BillingAdjustment::TYPE_DEBIT) {
                DB::table('invoices')
                    ->where('id', $invoice->getKey())
                    ->update([
                        'total_minor' => $invoice->total_minor + $adjustment->amount_minor,
                        'updated_at' => now(),
                    ]);
            }

            $affected = DB::table('billing_adjustments')
                ->where('id', $adjustmentId)
                ->where('status', BillingAdjustment::STATUS_APPROVED)
                ->where('lock_version', $adjustment->lock_version)
                ->update([
                    'status' => BillingAdjustment::STATUS_APPLIED,
                    'applied_by' => $applierId,
                    'applied_at' => now(),
                    'lock_version' => $adjustment->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This adjustment was modified. Reload and retry.', 409);
            }

            return $adjustment->refresh();
        });
    }

    /**
     * Reject a pending adjustment.
     */
    public function rejectAdjustment(
        string $tenantId,
        string $adjustmentId,
        string $rejectionReason,
        ?string $rejectedBy = null,
    ): BillingAdjustment {
        return DB::transaction(function () use ($tenantId, $adjustmentId, $rejectionReason): BillingAdjustment {
            $adjustment = BillingAdjustment::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $adjustmentId)
                ->first();

            if ($adjustment === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Billing adjustment not found.', 404);
            }

            if ($adjustment->status !== BillingAdjustment::STATUS_PENDING) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a pending adjustment can be rejected.', 409);
            }

            $affected = DB::table('billing_adjustments')
                ->where('id', $adjustmentId)
                ->where('status', BillingAdjustment::STATUS_PENDING)
                ->where('lock_version', $adjustment->lock_version)
                ->update([
                    'status' => BillingAdjustment::STATUS_REJECTED,
                    'reason_note' => $adjustment->reason_note."\nRejection: ".$rejectionReason,
                    'lock_version' => $adjustment->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This adjustment was modified. Reload and retry.', 409);
            }

            return $adjustment->refresh();
        });
    }

    private function nextNumber(string $tenantId): string
    {
        do {
            $number = 'ADJ-'.date('Ymd').'-'.random_int(10000, 99999);
        } while (BillingAdjustment::query()->where('tenant_id', $tenantId)->where('adjustment_number', $number)->exists());

        return $number;
    }
}
