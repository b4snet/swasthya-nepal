<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Charge;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Notification;
use App\Models\PharmacyReturn;
use App\Models\PrescriptionLine;
use App\Models\RefundRequest;
use App\Models\StockBatch;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;

/**
 * Pharmacy return/reversal (DATABASE.md §3.30, PRODUCT_REQUIREMENTS §6.7):
 * a pharmacist returns part or all of a dispensed line in ONE atomic
 * transaction.
 *
 *   dispensed line → returned_quantity_minor advanced + stock restored
 *   (ledger) + reversal record + refund request opened against the linked
 *   posted charge.
 *
 *  - The line row is locked (SELECT … FOR UPDATE), so concurrent returns
 *    serialize: the loser re-reads the advanced returned_quantity_minor and
 *    either completes a smaller return or is refused with CONFLICT. The
 *    CAS on (status, returned_quantity_minor) makes a stale actor affect
 *    zero rows, and the CHECK (returned_quantity_minor <= quantity_minor)
 *    is the database backstop — over-return is impossible.
 *  - A PARTIAL return keeps the line 'dispensed' (its history is not fully
 *    reversed); the line flips to 'reversed' only when the FULL dispensed
 *    quantity has been returned.
 *  - Stock restoration is a CAS on (quantity_on_hand, lock_version) and the
 *    ledger records the positive 'return' movement — the mirror of the
 *    negative 'dispense' movement — for EXACTLY the returned quantity.
 *  - The posted charge is NEVER mutated (immutable financial rows,
 *    MASTER_RULES §37.3): each return opens its OWN refund request for
 *    exactly `unit price × returned quantity` (unit price is exact integer
 *    minor units = amount_minor / dispensed quantity, since the charge is
 *    price × quantity). The refund layer's refundable check (amount − Σ
 *    approved) and the approval-time charge-row lock already prevent
 *    over-refund across multiple partial requests.
 *  - Each return also creates ONE in-app billing notification (type
 *    'billing', DATABASE.md §3.37) typed to the opened refund request — the
 *    documented "automatic notification to billing on return" integration.
 *    It lives in the SAME transaction as the return (both succeed or
 *    neither); the partial unique (tenant_id, refund_request_id) makes a
 *    duplicate a database-level no-op. In-app dispatch is synchronous
 *    (created 'sent') — no provider round-trip, nothing external, and the
 *    return's financial consistency never depends on delivery.
 */
final class PharmacyReturnService
{
    /**
     * Return part or all of a dispensed prescription line.
     *
     * @param  int|null  $quantity  the quantity to return; NULL returns the
     *                              FULL remaining returnable quantity (the
     *                              backward-compatible whole-line default).
     * @return array{return: PharmacyReturn, refundRequest: RefundRequest, notification: Notification}
     */
    public function reverseLine(
        string $tenantId,
        string $lineId,
        string $reasonCode,
        ?string $reasonNote,
        ?string $returnedByStaffId,
        ?string $userId,
        ?int $quantity = null,
    ): array {
        return DB::transaction(function () use ($tenantId, $lineId, $reasonCode, $reasonNote, $returnedByStaffId, $userId, $quantity): array {
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

            $dispensedQuantity = max(1, (int) ($line->quantity_minor ?? 1));
            $returnedSoFar = (int) ($line->returned_quantity_minor ?? 0);
            $remaining = $dispensedQuantity - $returnedSoFar;

            if ($remaining <= 0) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This line has already been fully returned.', 409);
            }

            $returnQuantity = $quantity ?? $remaining;

            if ($returnQuantity < 1) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The returned quantity must be at least 1.', 422);
            }

            if ($returnQuantity > $remaining) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    sprintf('Return of %d exceeds the remaining returnable quantity of %d.', $returnQuantity, $remaining),
                    422,
                );
            }

            $facilityId = $encounter->facility_id;

            // The exact posted charge created for this line at dispensing
            // (charges carry prescription_line_id from slice 8 forward).
            $charge = Charge::query()
                ->where('tenant_id', $tenantId)
                ->where('prescription_line_id', $line->getKey())
                ->where('status', Charge::STATUS_POSTED)
                ->first();

            if ($charge === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'No posted charge is linked to this dispensed line; it cannot be returned.', 409);
            }

            // Restore stock: CAS on the same shelf the dispense deducted
            // from. When the line was dispensed from a batch, the batch is
            // restored FIRST (the exact lot returns); the aggregate shelf
            // restore still applies (ledger truth).
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
                        'quantity_remaining' => DB::raw('quantity_remaining + '.$returnQuantity),
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
                    'quantity_on_hand' => DB::raw('quantity_on_hand + '.$returnQuantity),
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
                'quantity_delta' => $returnQuantity,
                'reason' => $medication->generic_name.' return',
                'prescription_line_id' => $line->getKey(),
                // Phase 3 slice 17 — the exact lot restored.
                'stock_batch_id' => $line->batch_id,
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);

            // Advance the line's returned accounting (CAS on the snapshot):
            // a stale concurrent returner affects zero rows and gets a
            // LOCK_CONFLICT. The line flips to 'reversed' only when the FULL
            // dispensed quantity has been returned — a partial return keeps
            // it 'dispensed' (its dispense history stays visible).
            $fullyReturned = ($returnedSoFar + $returnQuantity) >= $dispensedQuantity;
            $lineAdvanced = DB::table('prescription_lines')
                ->where('tenant_id', $tenantId)
                ->where('id', $line->getKey())
                ->where('status', PrescriptionLine::STATUS_DISPENSED)
                ->where('returned_quantity_minor', $returnedSoFar)
                ->update([
                    'returned_quantity_minor' => DB::raw('returned_quantity_minor + '.$returnQuantity),
                    'status' => $fullyReturned ? PrescriptionLine::STATUS_REVERSED : PrescriptionLine::STATUS_DISPENSED,
                    'updated_at' => now(),
                ]);

            if ($lineAdvanced !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This line was changed by another return. Reload and retry.', 409);
            }

            // The immutable reversal record — one per return event.
            $pharmacyReturn = PharmacyReturn::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'prescription_line_id' => $line->getKey(),
                'prescription_id' => $prescription->getKey(),
                'charge_id' => $charge->getKey(),
                'quantity_minor' => $returnQuantity,
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
                'returned_by' => $returnedByStaffId,
                'returned_at' => now(),
                'created_by' => $userId,
            ]);

            // The refund path: open a billing refund REQUEST against the
            // linked charge for EXACTLY this return's money value (unit price
            // × returned quantity — unit price is exact integer minor units
            // because the charge is price × quantity). The charge itself
            // stays immutable; approval is the billing approver's separate,
            // segregation-of-duties-gated action — no money moves here.
            $unitPrice = (int) ($charge->amount_minor / $dispensedQuantity);
            $refundAmount = $unitPrice * $returnQuantity;

            $refundRequest = RefundRequest::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $charge->patient_id,
                'charge_id' => $charge->getKey(),
                'amount_minor' => $refundAmount,
                'reason_code' => RefundRequest::REASON_PATIENT_REQUEST,
                'reason_note' => null,
                'status' => RefundRequest::STATUS_REQUESTED,
                'requested_by' => $userId,
                'lock_version' => 0,
                'created_by' => $userId,
            ]);

            // The billing notification — one per refund request, in the same
            // transaction (the return and its notification are atomic). The
            // partial unique (tenant_id, refund_request_id) is the database
            // backstop: a duplicate insert is a no-op, never a second
            // notification. Payload carries FACTS only — ids, the integer
            // amount, the reason code — never names or free text (§3.37
            // sensitive flag: the notification references a patient's
            // financial matter).
            $notification = Notification::query()->create([
                'tenant_id' => $tenantId,
                'patient_id' => $charge->patient_id,
                'refund_request_id' => $refundRequest->getKey(),
                'type' => Notification::TYPE_BILLING,
                'channel' => Notification::CHANNEL_IN_APP,
                'payload' => [
                    'refundRequestId' => $refundRequest->getKey(),
                    'chargeId' => $charge->getKey(),
                    'amountMinor' => $refundAmount,
                    'reasonCode' => $refundRequest->reason_code,
                ],
                'status' => Notification::STATUS_SENT,
                'sensitive' => true,
            ]);

            return [
                'return' => $pharmacyReturn,
                'refundRequest' => $refundRequest,
                'notification' => $notification,
            ];
        });
    }
}
