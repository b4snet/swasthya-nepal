<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Charge;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\PharmacyReturn;
use App\Models\PrescriptionLine;
use App\Models\RefundRequest;
use App\Models\StockBatch;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;

/**
 * Pharmacy return/reversal (DATABASE.md §3.30, PRODUCT_REQUIREMENTS §6.7):
 * a pharmacist reverses a dispensed line in ONE atomic transaction.
 *
 *   dispensed line → line reversed + stock restored (ledger) + reversal
 *   record + refund request opened against the linked posted charge.
 *
 *  - The line row is locked (SELECT … FOR UPDATE), so concurrent returns
 *    serialize: the loser reads status 'reversed' and gets a CONFLICT. The
 *    unique (tenant_id, prescription_line_id) index on pharmacy_returns is
 *    the database backstop — a line can never be returned twice.
 *  - Stock restoration is a CAS on (quantity_on_hand, lock_version) and the
 *    ledger records the positive 'return' movement — the mirror of the
 *    negative 'dispense' movement.
 *  - The posted charge is NEVER mutated (immutable financial rows,
 *    MASTER_RULES §37.3): the refund path opens through a refund_requests
 *    row (requested → approved by the billing approver), preserving the
 *    financial gate from slice 5.
 */
final class PharmacyReturnService
{
    /**
     * Reverse a dispensed prescription line.
     *
     * @return array{return: PharmacyReturn, refundRequest: RefundRequest}
     */
    public function reverseLine(
        string $tenantId,
        string $lineId,
        string $reasonCode,
        ?string $reasonNote,
        ?string $returnedByStaffId,
        ?string $userId,
    ): array {
        return DB::transaction(function () use ($tenantId, $lineId, $reasonCode, $reasonNote, $returnedByStaffId, $userId): array {
            // Lock the line: concurrent returns serialize here.
            $line = PrescriptionLine::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $lineId)
                ->lockForUpdate()
                ->with(['prescription.encounter', 'medication'])
                ->first();

            if ($line === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Prescription line not found.', 404);
            }

            if ($line->status !== PrescriptionLine::STATUS_DISPENSED) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'Only a dispensed line can be returned; this line is '.$line->status.'.',
                    409,
                );
            }

            $prescription = $line->prescription;
            $encounter = $prescription?->encounter;

            if ($encounter === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This prescription has no encounter; the line cannot be returned.', 409);
            }

            $medication = $line->medication;
            if ($medication === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The line references a medication that no longer exists; it cannot be returned.', 409);
            }

            $quantity = max(1, (int) ($line->quantity_minor ?? 1));
            $facilityId = $encounter->facility_id;

            // The exact posted charge created for this line at dispensing
            // (charges carry prescription_line_id from this slice forward).
            $charge = Charge::query()
                ->where('tenant_id', $tenantId)
                ->where('prescription_line_id', $line->getKey())
                ->where('status', Charge::STATUS_POSTED)
                ->first();

            if ($charge === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'No posted charge is linked to this dispensed line; it cannot be returned.', 409);
            }

            // Restore stock: CAS on the same shelf the dispense deducted
            // from. Phase 3 slice 17 — when the line was dispensed from a
            // batch, the batch is restored FIRST (the exact lot returns);
            // the aggregate shelf restore still applies (ledger truth).
            $item = InventoryItem::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('medication_id', $line->medication_id)
                ->first();

            if ($item === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'No stock is configured for '.$medication->generic_name.' at this facility; the line cannot be returned.', 409);
            }

            if ($line->batch_id !== null) {
                $batch = StockBatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('facility_id', $facilityId)
                    ->where('id', $line->batch_id)
                    ->lockForUpdate()
                    ->first();

                if ($batch === null) {
                    throw new ApiException(ErrorCodes::CONFLICT, 'The dispensed batch no longer exists; the line cannot be returned.', 409);
                }

                $batchRestored = DB::table('stock_batches')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $batch->getKey())
                    ->where('status', StockBatch::STATUS_AVAILABLE)
                    ->where('lock_version', $batch->lock_version)
                    ->update([
                        'quantity_remaining' => DB::raw('quantity_remaining + '.$quantity),
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_by' => $userId,
                        'updated_at' => now(),
                    ]);

                if ($batchRestored !== 1) {
                    throw new ApiException(ErrorCodes::CONFLICT, 'The dispensed batch was concurrently modified; refresh and retry.', 409);
                }
            }

            $restored = DB::table('inventory_items')
                ->where('tenant_id', $tenantId)
                ->where('id', $item->getKey())
                ->where('lock_version', $item->lock_version)
                ->update([
                    'quantity_on_hand' => DB::raw('quantity_on_hand + '.$quantity),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);

            if ($restored !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The stock shelf was concurrently modified; refresh and retry.', 409);
            }

            InventoryMovement::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'inventory_item_id' => $item->getKey(),
                'movement_type' => InventoryMovement::TYPE_RETURN,
                'quantity_delta' => $quantity,
                'reason' => $medication->generic_name.' return',
                'prescription_line_id' => $line->getKey(),
                // Phase 3 slice 17 — the exact lot restored.
                'stock_batch_id' => $line->batch_id,
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);

            // Line state: dispensed → reversed (CAS on status — a concurrent
            // return reads 'reversed' and can never advance it again).
            $lineAdvanced = DB::table('prescription_lines')
                ->where('tenant_id', $tenantId)
                ->where('id', $line->getKey())
                ->where('status', PrescriptionLine::STATUS_DISPENSED)
                ->update([
                    'status' => PrescriptionLine::STATUS_REVERSED,
                    'updated_at' => now(),
                ]);

            if ($lineAdvanced !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This line was changed by another return. Reload and retry.', 409);
            }

            // The immutable reversal record.
            $pharmacyReturn = PharmacyReturn::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'prescription_line_id' => $line->getKey(),
                'prescription_id' => $prescription->getKey(),
                'charge_id' => $charge->getKey(),
                'quantity_minor' => $quantity,
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
                'returned_by' => $returnedByStaffId,
                'returned_at' => now(),
                'created_by' => $userId,
            ]);

            // The refund path: open a billing refund REQUEST against the
            // linked charge (the charge itself stays immutable). Approval is
            // the billing approver's separate, segregation-of-duties-gated
            // action — no money moves here.
            $refundRequest = RefundRequest::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $charge->patient_id,
                'charge_id' => $charge->getKey(),
                'amount_minor' => $charge->amount_minor,
                'reason_code' => RefundRequest::REASON_PATIENT_REQUEST,
                'reason_note' => null,
                'status' => RefundRequest::STATUS_REQUESTED,
                'requested_by' => $userId,
                'lock_version' => 0,
                'created_by' => $userId,
            ]);

            return ['return' => $pharmacyReturn, 'refundRequest' => $refundRequest];
        });
    }
}
