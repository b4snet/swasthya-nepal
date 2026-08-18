<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\PurchaseRequestLine;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 14 — Procurement (PRODUCT_REQUIREMENTS §6.16, DATABASE.md §3.32):
 * vendor master + contracts, purchase request → approval (requester never
 * approves their own) → purchase order (contract prices enforced) → goods
 * receipt (stock-in, PO lines CAS-advanced) → three-way match → PO close.
 *
 * The three-way match compares each GRN line (received quantity, received
 * unit price) against its PO line (ordered quantity, PO unit price). A
 * mismatch can never reach `matched`; the PO closes (`received`) only when
 * every line is fully received AND every GRN is matched — the payment gate.
 *
 * The movement ledger stays the ONLY stock truth: GRN stock-in and
 * inter-facility transfers write `receipt`/`transfer` ledger rows and CAS
 * the item — nothing here mutates stock without a ledger row.
 */
final class ProcurementService
{
    /* ------------------------------------------------------------------ */
    /* Vendors + contracts */
    /* ------------------------------------------------------------------ */

    public function createVendor(string $tenantId, string $facilityId, string $code, string $name, ?string $taxId, ?string $bankDetails, ?string $createdBy = null): Vendor
    {
        return Vendor::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $code,
            'name' => $name,
            'tax_id_encrypted' => $taxId,
            'bank_details_encrypted' => $bankDetails,
            'status' => Vendor::STATUS_ACTIVE,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);
    }

    public function blacklistVendor(string $tenantId, string $vendorId, ?string $actorId = null): Vendor
    {
        return DB::transaction(function () use ($tenantId, $vendorId, $actorId): Vendor {
            $vendor = Vendor::query()->where('tenant_id', $tenantId)->where('id', $vendorId)->lockForUpdate()->first();
            if ($vendor === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Vendor not found.', 404);
            }
            if ($vendor->status === Vendor::STATUS_BLACKLISTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This vendor is already blacklisted.', 409);
            }
            $vendor->update(['status' => Vendor::STATUS_BLACKLISTED, 'updated_by' => $actorId]);

            return $vendor;
        });
    }

    public function createContract(
        string $tenantId,
        string $facilityId,
        string $vendorId,
        string $medicationId,
        int $unitPriceMinor,
        string $validFrom,
        string $validTo,
        ?string $terms,
        ?string $createdBy = null,
    ): VendorContract {
        return VendorContract::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'vendor_id' => $vendorId,
            'medication_id' => $medicationId,
            'unit_price_minor' => $unitPriceMinor,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'terms' => $terms,
            'status' => VendorContract::STATUS_ACTIVE,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Purchase requests */
    /* ------------------------------------------------------------------ */

    /**
     * Create a draft purchase request with lines.
     *
     * @param  list<array{medicationId: string, quantity: int, estimatedUnitPriceMinor: int}>  $lines
     */
    public function createRequest(
        string $tenantId,
        string $facilityId,
        array $lines,
        ?string $departmentId,
        ?string $createdBy = null,
    ): PurchaseRequest {
        if ($lines === []) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'At least one line is required.', 422);
        }

        return DB::transaction(function () use ($tenantId, $facilityId, $lines, $departmentId, $createdBy): PurchaseRequest {
            $request = PurchaseRequest::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'request_number' => $this->nextNumber($tenantId, 'PR', PurchaseRequest::class, 'request_number'),
                'requested_by' => $createdBy,
                'department_id' => $departmentId,
                'status' => PurchaseRequest::STATUS_DRAFT,
                'requested_at' => now(),
                'lock_version' => 0,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);

            foreach ($lines as $line) {
                PurchaseRequestLine::query()->create([
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'purchase_request_id' => $request->getKey(),
                    'medication_id' => $line['medicationId'],
                    'quantity' => $line['quantity'],
                    'estimated_unit_price_minor' => $line['estimatedUnitPriceMinor'],
                ]);
            }

            return $request;
        });
    }

    public function submitRequest(string $tenantId, string $requestId, ?string $actorId = null): PurchaseRequest
    {
        return DB::transaction(function () use ($tenantId, $requestId, $actorId): PurchaseRequest {
            $request = PurchaseRequest::query()->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Purchase request not found.', 404);
            }
            if ($request->status !== PurchaseRequest::STATUS_DRAFT) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a draft purchase request can be submitted.', 409);
            }
            if ($request->lines()->count() === 0) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'A purchase request needs at least one line before submission.', 422);
            }

            $this->casPurchaseRequest($request, PurchaseRequest::STATUS_DRAFT, [
                'status' => PurchaseRequest::STATUS_SUBMITTED,
                'requested_at' => now(),
                'updated_by' => $actorId,
            ]);

            return $request->refresh();
        });
    }

    public function approveRequest(string $tenantId, string $requestId, ?string $approverId = null): PurchaseRequest
    {
        return DB::transaction(function () use ($tenantId, $requestId, $approverId): PurchaseRequest {
            $request = PurchaseRequest::query()->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Purchase request not found.', 404);
            }
            if ($request->status !== PurchaseRequest::STATUS_SUBMITTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a submitted purchase request can be approved.', 409);
            }
            if ($approverId !== null && $request->requested_by === $approverId) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot approve their own purchase request.', 403);
            }

            $this->casPurchaseRequest($request, PurchaseRequest::STATUS_SUBMITTED, [
                'status' => PurchaseRequest::STATUS_APPROVED,
                'updated_by' => $approverId,
            ]);

            PurchaseRequestApproval::query()->create([
                'tenant_id' => $tenantId,
                'purchase_request_id' => $requestId,
                'approver_id' => $approverId,
                'decision' => PurchaseRequestApproval::DECISION_APPROVED,
                'decided_at' => now(),
            ]);

            return $request->refresh();
        });
    }

    public function rejectRequest(string $tenantId, string $requestId, string $rejectionReason, ?string $rejectedBy = null): PurchaseRequest
    {
        return DB::transaction(function () use ($tenantId, $requestId, $rejectionReason, $rejectedBy): PurchaseRequest {
            $request = PurchaseRequest::query()->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Purchase request not found.', 404);
            }
            if ($request->status !== PurchaseRequest::STATUS_SUBMITTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a submitted purchase request can be rejected.', 409);
            }
            if ($rejectedBy !== null && $request->requested_by === $rejectedBy) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot reject their own purchase request.', 403);
            }

            $this->casPurchaseRequest($request, PurchaseRequest::STATUS_SUBMITTED, [
                'status' => PurchaseRequest::STATUS_REJECTED,
                'updated_by' => $rejectedBy,
            ]);

            PurchaseRequestApproval::query()->create([
                'tenant_id' => $tenantId,
                'purchase_request_id' => $requestId,
                'approver_id' => $rejectedBy,
                'decision' => PurchaseRequestApproval::DECISION_REJECTED,
                'reason' => $rejectionReason,
                'decided_at' => now(),
            ]);

            return $request->refresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Purchase orders */
    /* ------------------------------------------------------------------ */

    /**
     * Issue a PO from an approved request against a vendor. Contract prices
     * are ENFORCED: when an active contract covers (vendor, medication), the
     * PO line price must equal the contract price — a deviation is refused.
     */
    public function issueOrder(
        string $tenantId,
        string $facilityId,
        string $requestId,
        string $vendorId,
        ?string $expectedDelivery,
        ?string $createdBy = null,
    ): PurchaseOrder {
        return DB::transaction(function () use ($tenantId, $facilityId, $requestId, $vendorId, $expectedDelivery, $createdBy): PurchaseOrder {
            $request = PurchaseRequest::query()->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Purchase request not found.', 404);
            }
            if ($request->status !== PurchaseRequest::STATUS_APPROVED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an approved purchase request can be ordered.', 409);
            }
            if ($request->facility_id !== $facilityId) {
                throw new ApiException(ErrorCodes::SCOPE_DENIED, 'The request belongs to a different facility.', 403);
            }

            $vendor = Vendor::query()->where('tenant_id', $tenantId)->where('id', $vendorId)->first();
            if ($vendor === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Vendor not found.', 404);
            }
            if ($vendor->status !== Vendor::STATUS_ACTIVE) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A blacklisted vendor cannot receive a purchase order.', 409);
            }

            $contractPrices = $this->activeContractPrices($tenantId, $vendorId);

            $order = PurchaseOrder::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'po_number' => $this->nextNumber($tenantId, 'PO', PurchaseOrder::class, 'po_number'),
                'vendor_id' => $vendorId,
                'status' => PurchaseOrder::STATUS_ISSUED,
                'expected_delivery' => $expectedDelivery,
                'lock_version' => 0,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);

            $request->lines()->get()->each(function (PurchaseRequestLine $line) use ($tenantId, $facilityId, $order, $contractPrices): void {
                $contractPrice = $contractPrices[$line->medication_id] ?? null;
                if ($contractPrice !== null && $line->estimated_unit_price_minor !== $contractPrice) {
                    throw new ApiException(
                        ErrorCodes::CONFLICT,
                        'A contract sets the price for this medication; the PO must match the contract price.',
                        409,
                    );
                }

                PurchaseOrderLine::query()->create([
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'po_id' => $order->getKey(),
                    'medication_id' => $line->medication_id,
                    'quantity_ordered' => $line->quantity,
                    'unit_price_minor' => $line->estimated_unit_price_minor,
                    'received_quantity' => 0,
                    'lock_version' => 0,
                ]);
            });

            $this->casPurchaseRequest($request, PurchaseRequest::STATUS_APPROVED, [
                'status' => PurchaseRequest::STATUS_ORDERED,
                'updated_by' => $createdBy,
            ]);

            return $order;
        });
    }

    public function confirmOrder(string $tenantId, string $orderId, ?string $actorId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($tenantId, $orderId, $actorId): PurchaseOrder {
            $order = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Purchase order not found.', 404);
            }
            if ($order->status !== PurchaseOrder::STATUS_ISSUED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an issued purchase order can be confirmed.', 409);
            }

            $this->casPurchaseOrder($order, PurchaseOrder::STATUS_ISSUED, [
                'status' => PurchaseOrder::STATUS_CONFIRMED,
                'updated_by' => $actorId,
            ]);

            return $order->refresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Goods receipt + three-way match */
    /* ------------------------------------------------------------------ */

    /**
     * Receive goods against PO lines: create the GRN (status `received`) and
     * its lines, apply stock-in (receipt movements + item CAS) and advance
     * the PO lines' received quantities — all in ONE transaction. Partial
     * receipts are allowed (quantity <= the line's remaining). One GRN line
     * per PO line per GRN (partial unique).
     *
     * @param  list<array{poLineId: string, quantity: int, unitPriceMinor: int}>  $lines
     * @return array{grn: GoodsReceipt, itemMovements: array<string, int>}
     */
    public function receiveGoods(
        string $tenantId,
        string $facilityId,
        string $orderId,
        array $lines,
        ?string $receivedBy = null,
    ): array {
        if ($lines === []) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'At least one received line is required.', 422);
        }

        return DB::transaction(function () use ($tenantId, $facilityId, $orderId, $lines, $receivedBy): array {
            $order = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Purchase order not found.', 404);
            }
            if (! in_array($order->status, [PurchaseOrder::STATUS_ISSUED, PurchaseOrder::STATUS_CONFIRMED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This purchase order cannot receive goods.', 409);
            }
            if ($order->facility_id !== $facilityId) {
                throw new ApiException(ErrorCodes::SCOPE_DENIED, 'The order belongs to a different facility.', 403);
            }

            $grn = GoodsReceipt::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'grn_number' => $this->nextNumber($tenantId, 'GRN', GoodsReceipt::class, 'grn_number'),
                'po_id' => $orderId,
                'received_by' => $receivedBy,
                'received_at' => now(),
                'status' => GoodsReceipt::STATUS_RECEIVED,
                'match_status' => null,
                'created_by' => $receivedBy,
                'updated_by' => $receivedBy,
            ]);

            $allFullyReceived = true;
            $itemMovements = [];

            foreach ($lines as $line) {
                $poLine = PurchaseOrderLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('po_id', $orderId)
                    ->where('id', $line['poLineId'])
                    ->lockForUpdate()
                    ->first();

                if ($poLine === null) {
                    throw new ApiException(ErrorCodes::NOT_FOUND, 'PO line not found.', 404);
                }

                $remaining = $poLine->quantity_ordered - $poLine->received_quantity;
                if ($line['quantity'] <= 0 || $line['quantity'] > $remaining) {
                    throw new ApiException(
                        ErrorCodes::VALIDATION_ERROR,
                        sprintf('Received quantity exceeds the %d units remaining on the PO line.', $remaining),
                        422,
                    );
                }

                // One GRN line per PO line per GRN (partial unique backstop).
                GoodsReceiptLine::query()->create([
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'grn_id' => $grn->getKey(),
                    'po_line_id' => $poLine->getKey(),
                    'medication_id' => $poLine->medication_id,
                    'quantity_received' => $line['quantity'],
                    'unit_price_received' => $line['unitPriceMinor'],
                ]);

                // CAS the PO line's cumulative received quantity.
                $affected = DB::table('purchase_order_lines')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $poLine->getKey())
                    ->where('lock_version', $poLine->lock_version)
                    ->where('quantity_ordered', '>=', $poLine->received_quantity + $line['quantity'])
                    ->update([
                        'received_quantity' => $poLine->received_quantity + $line['quantity'],
                        'lock_version' => $poLine->lock_version + 1,
                        'updated_at' => now(),
                    ]);

                if ($affected !== 1) {
                    throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The PO line was changed by another receipt. Reload and retry.', 409);
                }

                if ($poLine->received_quantity + $line['quantity'] < $poLine->quantity_ordered) {
                    $allFullyReceived = false;
                }

                // Stock-in: upsert the facility item + receipt ledger row.
                $itemMovement = $this->applyStockIn($tenantId, $facilityId, $poLine->medication_id, $line['quantity'], $grn->getKey(), $receivedBy);
                $itemMovements[$poLine->medication_id] = $itemMovement;
            }

            $this->casPurchaseOrder($order, $order->status, [
                'status' => $allFullyReceived ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                'updated_by' => $receivedBy,
            ]);

            return ['grn' => $grn->refresh(), 'itemMovements' => $itemMovements];
        });
    }

    /**
     * Three-way match: every GRN line against its PO line — received
     * quantity must not exceed ordered (guaranteed at receipt) AND received
     * unit price must equal the PO unit price. All lines equal → `matched`;
     * any deviation → `mismatch`. A mismatch can never reach `matched` and
     * blocks the PO close (the payment gate). Idempotent (CAS on status).
     */
    public function matchReceipt(string $tenantId, string $grnId): GoodsReceipt
    {
        return DB::transaction(function () use ($tenantId, $grnId): GoodsReceipt {
            $grn = GoodsReceipt::query()->where('tenant_id', $tenantId)->where('id', $grnId)->lockForUpdate()->first();
            if ($grn === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Goods receipt not found.', 404);
            }
            if ($grn->status === GoodsReceipt::STATUS_MATCHED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This goods receipt is already matched.', 409);
            }
            if ($grn->status !== GoodsReceipt::STATUS_RECEIVED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a received goods receipt can be matched.', 409);
            }

            $mismatch = false;

            $grn->lines()->with('poLine')->get()->each(function (GoodsReceiptLine $line) use (&$mismatch): void {
                $poLine = $line->poLine;
                if ($poLine === null) {
                    $mismatch = true;

                    return;
                }
                if ($line->quantity_received > $poLine->quantity_ordered || $line->unit_price_received !== $poLine->unit_price_minor) {
                    $mismatch = true;
                }
            });

            $affected = DB::table('goods_receipts')
                ->where('tenant_id', $tenantId)
                ->where('id', $grnId)
                ->where('status', GoodsReceipt::STATUS_RECEIVED)
                ->update([
                    'status' => $mismatch ? GoodsReceipt::STATUS_RECEIVED : GoodsReceipt::STATUS_MATCHED,
                    'match_status' => $mismatch ? GoodsReceipt::MATCH_MISMATCH : GoodsReceipt::MATCH_MATCHED,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This goods receipt was changed by another actor. Reload and retry.', 409);
            }

            return $grn->refresh();
        });
    }

    /**
     * Close a PO as fully received: every line fully received AND every GRN
     * three-way MATCHED. A mismatched or unmatched GRN blocks the close —
     * the documented "mismatches block payment" gate.
     */
    public function closeOrder(string $tenantId, string $orderId, ?string $actorId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($tenantId, $orderId, $actorId): PurchaseOrder {
            $order = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Purchase order not found.', 404);
            }
            if ($order->status !== PurchaseOrder::STATUS_RECEIVED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a fully received purchase order can be closed.', 409);
            }

            $unmatched = $order->receipts()
                ->where('tenant_id', $tenantId)
                ->where('po_id', $orderId)
                ->where('status', '!=', GoodsReceipt::STATUS_MATCHED)
                ->exists();

            if ($unmatched) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A goods receipt on this order is not three-way matched; payment is blocked.', 409);
            }

            $this->casPurchaseOrder($order, PurchaseOrder::STATUS_RECEIVED, [
                'status' => PurchaseOrder::STATUS_RECEIVED,
                'updated_by' => $actorId,
            ]);

            return $order->refresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Internal helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Contract unit prices currently in force for a vendor: medication_id →
     * unit_price_minor.
     *
     * @return array<string, int>
     */
    private function activeContractPrices(string $tenantId, string $vendorId): array
    {
        return VendorContract::query()
            ->where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('status', VendorContract::STATUS_ACTIVE)
            ->whereDate('valid_from', '<=', now()->toDateString())
            ->whereDate('valid_to', '>=', now()->toDateString())
            ->get()
            ->mapWithKeys(fn (VendorContract $c): array => [$c->medication_id => $c->unit_price_minor])
            ->all();
    }

    /**
     * Apply GRN stock-in: upsert the facility's inventory item (creating it
     * when the medication is not yet stocked at this facility) and write the
     * `receipt` ledger movement linked to the GRN line. Returns the item's
     * new on-hand quantity.
     */
    private function applyStockIn(
        string $tenantId,
        string $facilityId,
        string $medicationId,
        int $quantity,
        string $grnId,
        ?string $actorId,
    ): int {
        $grnLine = GoodsReceiptLine::query()
            ->where('tenant_id', $tenantId)
            ->where('grn_id', $grnId)
            ->where('medication_id', $medicationId)
            ->firstOrFail();

        $row = DB::selectOne(
            <<<'SQL'
            insert into inventory_items
                (id, tenant_id, facility_id, medication_id, quantity_on_hand, reorder_level, lock_version, created_by, updated_by, created_at, updated_at)
            values (?, ?, ?, ?, ?, null, 0, ?, ?, now(), now())
            on conflict (tenant_id, facility_id, medication_id) do update set
                quantity_on_hand = inventory_items.quantity_on_hand + excluded.quantity_on_hand,
                lock_version = inventory_items.lock_version + 1,
                updated_by = excluded.updated_by,
                updated_at = now()
            returning id, quantity_on_hand, lock_version
            SQL,
            [(string) Str::uuid(), $tenantId, $facilityId, $medicationId, $quantity, $actorId, $actorId],
        );

        InventoryMovement::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'inventory_item_id' => $row->id,
            'movement_type' => InventoryMovement::TYPE_RECEIPT,
            'quantity_delta' => $quantity,
            'reason' => 'Goods receipt '.$grnLine->receipt->grn_number,
            'goods_receipt_line_id' => $grnLine->getKey(),
            'occurred_at' => now(),
            'created_by' => $actorId,
        ]);

        return (int) $row->quantity_on_hand;
    }

    private function casPurchaseRequest(PurchaseRequest $request, string $expectedStatus, array $fields): void
    {
        $affected = DB::table('purchase_requests')
            ->where('tenant_id', $request->tenant_id)
            ->where('id', $request->getKey())
            ->where('status', $expectedStatus)
            ->where('lock_version', $request->lock_version)
            ->update([...$fields, 'lock_version' => $request->lock_version + 1, 'updated_at' => now()]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This purchase request was changed by another actor. Reload and retry.', 409);
        }
    }

    private function casPurchaseOrder(PurchaseOrder $order, string $expectedStatus, array $fields): void
    {
        $affected = DB::table('purchase_orders')
            ->where('tenant_id', $order->tenant_id)
            ->where('id', $order->getKey())
            ->where('status', $expectedStatus)
            ->where('lock_version', $order->lock_version)
            ->update([...$fields, 'lock_version' => $order->lock_version + 1, 'updated_at' => now()]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This purchase order was changed by another actor. Reload and retry.', 409);
        }
    }

    private function nextNumber(string $tenantId, string $prefix, string $model, string $column): string
    {
        do {
            $number = $prefix.'-'.date('Ymd').'-'.random_int(10000, 99999);
        } while ($model::query()->where('tenant_id', $tenantId)->where($column, $number)->exists());

        return $number;
    }
}
