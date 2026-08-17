<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\InventoryItem;
use App\Models\PrescriptionLine;
use App\Models\StockBatch;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 17 — Pharmacy batch/expiry + controlled-substance dual
 * verification (ROADMAP Phase 12, PRODUCT_REQUIREMENTS §6.7, DATABASE.md
 * §3.30/§3.31).
 *
 * Dispensing is batch-selected: either the pharmacist names the batch
 * (batchSelections) or the system picks FEFO (first-expiry-first-out)
 * among AVAILABLE, UNEXPIRED batches. Every batch movement is a CAS on
 * (quantity_remaining, lock_version, status) — an EXPIRED batch can never
 * be drawn (the guard refuses it), a depleted batch can never go negative,
 * and a concurrent dispense affects 0 rows → 409 (no double-dispense).
 *
 * Controlled substances with controlled_dispense_requires_dual demand a
 * SECOND pharmacist's verification (dispenser ≠ verifier — the entry ≠
 * verification discipline applied to pharmacy).
 */
final class PharmacyService
{
    /**
     * Pick the batch a line will be dispensed from (FEFO among available,
     * unexpired batches of the line's medication in the facility). Returns
     * null when the line has no stock at all (the caller's stock check
     * still governs).
     */
    public function fefoBatch(string $tenantId, string $facilityId, string $medicationId): ?StockBatch
    {
        return StockBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('medication_id', $medicationId)
            ->where('status', StockBatch::STATUS_AVAILABLE)
            ->where('expiry_date', '>', now()->toDateString())
            ->where('quantity_remaining', '>', 0)
            ->orderBy('expiry_date')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * The exact batch selected by the pharmacist for a line — validated
     * against the line's medication, tenant+facility, availability, and
     * expiry. Expired/unknown batches are refused with 422.
     */
    public function resolveSelectedBatch(string $tenantId, string $facilityId, string $medicationId, string $batchId): StockBatch
    {
        $batch = StockBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('medication_id', $medicationId)
            ->where('id', $batchId)
            ->lockForUpdate()
            ->first();

        if ($batch === null) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'The selected batch does not exist for this medication in this facility.',
                422,
            );
        }

        if ($batch->expiry_date->lte(now()->toDateString())) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The selected batch has expired and cannot be dispensed.',
                409,
            );
        }

        if ($batch->status !== StockBatch::STATUS_AVAILABLE || $batch->quantity_remaining <= 0) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The selected batch is not available for dispensing.',
                409,
            );
        }

        return $batch;
    }

    /**
     * Deduct from a batch with the expiry/availability CAS. The enclosing
     * transaction rolls back the whole dispense if this affects 0 rows.
     */
    public function deductFromBatch(StockBatch $batch, int $quantity, ?string $userId): void
    {
        $updated = DB::table('stock_batches')
            ->where('tenant_id', $batch->tenant_id)
            ->where('id', $batch->getKey())
            ->where('status', StockBatch::STATUS_AVAILABLE)
            ->where('expiry_date', '>', now()->toDateString())
            ->where('quantity_remaining', '>=', $quantity)
            ->where('lock_version', $batch->lock_version)
            ->update([
                'quantity_remaining' => DB::raw('quantity_remaining - '.$quantity),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The batch was concurrently modified, depleted, or expired; refresh and retry.',
                409,
            );
        }
    }

    /**
     * Deduct from the aggregate shelf with the same CAS discipline as the
     * batch deduction. Shared by prescription dispensing and the standalone
     * dispensing surface — the ledger remains the single stock truth, and a
     * stale/concurrent actor affects zero rows (409).
     */
    public function deductShelf(InventoryItem $item, int $quantity, ?string $userId): void
    {
        $updated = DB::table('inventory_items')
            ->where('tenant_id', $item->tenant_id)
            ->where('id', $item->getKey())
            ->where('lock_version', $item->lock_version)
            ->where('quantity_on_hand', '>=', $quantity)
            ->update([
                'quantity_on_hand' => DB::raw('quantity_on_hand - '.$quantity),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Insufficient stock or the shelf was concurrently modified; refresh and retry.',
                409,
            );
        }
    }

    /**
     * Whether a dispensed line still needs its second-pharmacist stamp.
     */
    public function requiresDualVerification(PrescriptionLine $line): bool
    {
        return $line->dual_verified_by_staff_id === null
            && $line->dual_verified_at === null;
    }

    /**
     * The second pharmacist verifies a dispensed controlled line.
     * The verifier must hold pharmacy:dispense (route gate) AND differ from
     * the dispenser; the line must be dispensed and still pending dual
     * verification (CAS — a concurrent verification affects 0 rows).
     */
    public function dualVerify(PrescriptionLine $line, string $verifierStaffId, ?string $userId): PrescriptionLine
    {
        if ($line->status !== PrescriptionLine::STATUS_DISPENSED) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Only a dispensed line can be dual-verified (current status: '.$line->status.').',
                409,
            );
        }

        if ($line->dispensed_by_staff_id === $verifierStaffId) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'The second pharmacist must be different from the pharmacist who dispensed the line.',
                403,
            );
        }

        if (! $this->requiresDualVerification($line)) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'This line has already been dual-verified.',
                409,
            );
        }

        $updated = DB::table('prescription_lines')
            ->where('tenant_id', $line->tenant_id)
            ->where('id', $line->getKey())
            ->where('status', PrescriptionLine::STATUS_DISPENSED)
            ->whereNull('dual_verified_by_staff_id')
            ->update([
                'dual_verified_by_staff_id' => $verifierStaffId,
                'dual_verified_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(
                ErrorCodes::LOCK_CONFLICT,
                'This line was dual-verified concurrently. Reload and retry.',
                409,
            );
        }

        return $line->fresh();
    }
}
