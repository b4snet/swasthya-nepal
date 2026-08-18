<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RejectAdjustmentRequest;
use App\Http\Requests\Inventory\StoreAdjustmentRequest;
use App\Http\Requests\Inventory\StoreTransferRequest;
use App\Http\Requests\Pharmacy\AdjustInventoryRequest;
use App\Http\Requests\Pharmacy\StoreInventoryRequest;
use App\Models\Facility;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\StockBatch;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pharmacy inventory (DATABASE.md §3.23): the stock shelf per medication
 * per facility, backed by the append-only inventory_movements ledger.
 *
 *   GET  organizations/{organization}/inventory            — stock view
 *   POST organizations/{organization}/inventory            — receipt
 *   POST inventory-items/{inventoryItem}/adjust            — adjustment
 *
 * Receipts and adjustments are atomic single-statement upserts/CAS; stock
 * can never go negative and every change is a ledger row.
 */
final class InventoryController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /organizations/{organization}/inventory — the stock view within
     * the caller's facility scope (RLS). Ordered by medication name.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        // Facility-scoped principals see exactly their facility's shelf (the
        // same explicit filter every org-scoped catalog list applies); org
        // and platform contexts see the whole tenant.
        $context = TenantContext::current();
        $query = InventoryItem::query()
            ->where('tenant_id', $organization->getKey())
            ->with('medication:id,generic_name,brand_name,strength,form,unit,is_controlled,price_minor,currency');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $items = $query->get()
            ->sortBy(fn (InventoryItem $item): string => $item->medication?->generic_name ?? '')
            ->values()
            ->map(fn (InventoryItem $item): array => $this->present($item))
            ->values();

        return Envelope::success(data: $items, request: $request);
    }

    /**
     * POST /organizations/{organization}/inventory — stock a medication
     * (receipt). Idempotent at the row level via an atomic upsert: concurrent
     * receipts of a brand-new item cannot race (one INSERT ... ON CONFLICT
     * DO UPDATE statement).
     */
    public function store(StoreInventoryRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();

        // A facility-scoped principal stocks THEIR facility (the proposed
        // id is ignored); an org/platform principal must name a facility of
        // the tenant — the established FacilityScope rule for catalog writes.
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);
        $facilityId = $facility->getKey();
        $medicationId = $request->validated('medicationId');
        $quantity = (int) $request->validated('quantity');
        $reorderLevel = $request->validated('reorderLevel') !== null ? (int) $request->validated('reorderLevel') : null;

        // The medication must belong to this organization and facility.
        $medication = Medication::query()
            ->where('tenant_id', $organization->getKey())
            ->where('facility_id', $facilityId)
            ->where('id', $medicationId)
            ->first();

        if ($medication === null) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The medication must be an active formulary item in this facility.', 422);
        }

        // Phase 3 slice 17 — optional batch receipt: {batchNumber,
        // expiryDate, controlledDispenseRequiresDual}. When supplied, the
        // batch row is created atomically with the shelf receipt.
        $batchInput = $request->input('batch');

        $item = DB::transaction(function () use ($organization, $facilityId, $medicationId, $quantity, $reorderLevel, $context, $medication, $batchInput): InventoryItem {
            $userId = $context->user?->getKey();
            $id = (string) Str::uuid();

            $row = DB::selectOne(
                <<<'SQL'
                insert into inventory_items
                    (id, tenant_id, facility_id, medication_id, quantity_on_hand, reorder_level, lock_version, created_by, updated_by, created_at, updated_at)
                values (?, ?, ?, ?, ?, ?, 0, ?, ?, now(), now())
                on conflict (tenant_id, facility_id, medication_id) do update set
                    quantity_on_hand = inventory_items.quantity_on_hand + excluded.quantity_on_hand,
                    reorder_level = coalesce(excluded.reorder_level, inventory_items.reorder_level),
                    lock_version = inventory_items.lock_version + 1,
                    updated_by = excluded.updated_by,
                    updated_at = now()
                returning id, quantity_on_hand, lock_version
                SQL,
                [$id, $organization->getKey(), $facilityId, $medicationId, $quantity, $reorderLevel, $userId, $userId],
            );

            $item = InventoryItem::query()->findOrFail($row->id);

            $movement = InventoryMovement::query()->create([
                'tenant_id' => $organization->getKey(),
                'facility_id' => $facilityId,
                'inventory_item_id' => $item->getKey(),
                'movement_type' => InventoryMovement::TYPE_RECEIPT,
                'quantity_delta' => $quantity,
                'reason' => $medication->generic_name.' receipt',
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);

            if ($batchInput !== null) {
                $batch = StockBatch::query()->create([
                    'tenant_id' => $organization->getKey(),
                    'facility_id' => $facilityId,
                    'inventory_item_id' => $item->getKey(),
                    'medication_id' => $medicationId,
                    'batch_number' => $batchInput['batchNumber'],
                    'expiry_date' => $batchInput['expiryDate'],
                    'quantity_received' => $quantity,
                    'quantity_remaining' => $quantity,
                    'status' => StockBatch::STATUS_AVAILABLE,
                    'controlled_dispense_requires_dual' => $batchInput['controlledDispenseRequiresDual'] ?? false,
                    'lock_version' => 0,
                    'created_by' => $userId,
                ]);

                $movement->update(['stock_batch_id' => $batch->getKey()]);
            }

            return $item;
        });

        $this->audit->record(
            'inventory.received',
            'inventory_item',
            $item->getKey(),
            ['facilityId' => $facilityId, 'medicationId' => $medicationId, 'quantity' => $quantity, 'newQuantityOnHand' => $item->quantity_on_hand],
            $request,
        );

        return Envelope::success(data: $this->present($item->fresh('medication:id,generic_name,brand_name,strength,form,unit,is_controlled,price_minor,currency')), status: 201, request: $request);
    }

    /**
     * GET /inventory-items/{inventoryItem}/batches — batch/expiry
     * visibility for one inventory item (ROADMAP Phase 12 acceptance:
     * "expiring/expired batches visible and never issuable"; PRODUCT_
     * REQUIREMENTS §6.7 "expiring-stock handling must be visible to staff").
     *
     * Returns every batch of the item — available, depleted, and
     * quarantined — ordered soonest expiry first (the urgent stock at the
     * top), each with its expiry status (`valid` / `expiring_soon` /
     * `expired`), days to expiry (negative when expired), per-batch stock,
     * and the controlled-substance flag. Expiry status is date-derived
     * presentation only — the dispensing CAS remains the hard expiry gate.
     * The endpoint is read-only: it mutates nothing and records no audit
     * (matching the inventory index).
     */
    public function batches(InventoryItem $inventoryItem, Request $request): JsonResponse
    {
        AccessCheck::scoped($inventoryItem, write: false);

        $context = TenantContext::current();

        $query = StockBatch::query()
            ->where('tenant_id', $inventoryItem->tenant_id)
            ->where('inventory_item_id', $inventoryItem->getKey());

        // Facility-scoped principals see exactly their facility's batches
        // (the item is facility-scoped already; this mirrors the index).
        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $batches = $query->orderBy('expiry_date')->orderBy('created_at')->get();

        return Envelope::success(
            data: $batches->map(fn (StockBatch $batch): array => $this->presentBatch($batch))->values(),
            request: $request,
        );
    }

    /**
     * POST /inventory-items/{inventoryItem}/adjust — a signed stock
     * adjustment with a mandatory reason. CAS on (quantity, lock_version):
     * concurrent adjustments cannot drive stock negative or double-apply.
     */
    public function adjust(AdjustInventoryRequest $request, InventoryItem $inventoryItem): JsonResponse
    {
        AccessCheck::scoped($inventoryItem, write: true);

        $context = TenantContext::current();
        $delta = (int) $request->validated('quantityDelta');
        $reason = $request->validated('reason');
        $userId = $context->user?->getKey();

        DB::transaction(function () use ($inventoryItem, $delta, $reason, $userId): void {
            $updated = DB::table('inventory_items')
                ->where('tenant_id', $inventoryItem->tenant_id)
                ->where('id', $inventoryItem->getKey())
                ->where('lock_version', $inventoryItem->lock_version)
                ->where('quantity_on_hand', '>=', -$delta)
                ->update([
                    'quantity_on_hand' => DB::raw('quantity_on_hand + '.$delta),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This adjustment would drive stock negative or the item was concurrently modified; refresh and retry.', 409);
            }

            InventoryMovement::query()->create([
                'tenant_id' => $inventoryItem->tenant_id,
                'facility_id' => $inventoryItem->facility_id,
                'inventory_item_id' => $inventoryItem->getKey(),
                'movement_type' => InventoryMovement::TYPE_ADJUSTMENT,
                'quantity_delta' => $delta,
                'reason' => $reason,
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);
        });

        $this->audit->record(
            'inventory.adjusted',
            'inventory_item',
            $inventoryItem->getKey(),
            ['facilityId' => $inventoryItem->facility_id, 'medicationId' => $inventoryItem->medication_id, 'quantityDelta' => $delta, 'newQuantityOnHand' => $inventoryItem->quantity_on_hand + $delta],
            $request,
        );

        return Envelope::success(data: $this->present($inventoryItem->fresh('medication:id,generic_name,brand_name,strength,form,unit,is_controlled,price_minor,currency')), request: $request);
    }

    /**
     * GET /organizations/{organization}/reorder-alerts — the documented
     * low-stock alert surface (PRODUCT_REQUIREMENTS §6.15.6, ROADMAP Phase
     * 14 MVP): every stocked item whose on-hand quantity is AT or BELOW its
     * reorder level, with the shortage (how many units below the level).
     * Read-only — no mutation, no audit (mirrors the batch-visibility
     * surface).
     */
    public function reorderAlerts(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = InventoryItem::query()
            ->where('tenant_id', $organization->getKey())
            ->whereNotNull('reorder_level')
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->with('medication:id,generic_name,brand_name,strength,form,unit,is_controlled,price_minor,currency');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $alerts = $query->get()->map(function (InventoryItem $item): array {
            $presented = $this->present($item);
            $presented['reorderLevel'] = $item->reorder_level;
            $presented['shortageMinor'] = max(0, $item->reorder_level - $item->quantity_on_hand);

            return $presented;
        })->values();

        return Envelope::success(data: $alerts, request: $request);
    }

    /**
     * POST /inventory-transfers — an inter-facility stock transfer
     * (PRODUCT_REQUIREMENTS §6.15.4). ATOMIC: the source item is
     * CAS-decremented and the destination item CAS-incremented in one
     * transaction with a paired `transfer` ledger movement on each side
     * (both linked to the transfer row). Stock never goes in-transit and
     * the movement ledger stays the only stock truth. Requires
     * inventory:transfer (org-level — a facility-scoped principal can
     * never write another facility's stock).
     */
    public function transfer(StoreTransferRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $source = InventoryItem::query()
            ->where('tenant_id', $context->tenantId())
            ->where('id', $request->validated('inventoryItemId'))
            ->first();

        if ($source === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Inventory item not found.', 404);
        }

        AccessCheck::scoped($source, write: true);

        $destinationFacility = Facility::query()
            ->where('tenant_id', $context->tenantId())
            ->where('id', $request->validated('destinationFacilityId'))
            ->first();

        if ($destinationFacility === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Destination facility not found.', 404);
        }

        $destination = InventoryItem::query()
            ->where('tenant_id', $context->tenantId())
            ->where('facility_id', $destinationFacility->getKey())
            ->where('medication_id', $source->medication_id)
            ->first();

        if ($destination === null) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The destination facility has no stock row for this medication; stock it first.', 409);
        }

        $quantity = (int) $request->validated('quantity');
        $reason = $request->validated('reason');
        $userId = $context->user?->getKey();

        $transfer = DB::transaction(function () use ($context, $source, $destination, $destinationFacility, $quantity, $reason, $userId): InventoryTransfer {
            // Source: CAS down (never negative).
            $sourceUpdated = DB::table('inventory_items')
                ->where('tenant_id', $source->tenant_id)
                ->where('id', $source->getKey())
                ->where('lock_version', $source->lock_version)
                ->where('quantity_on_hand', '>=', $quantity)
                ->update([
                    'quantity_on_hand' => DB::raw('quantity_on_hand - '.$quantity),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);

            if ($sourceUpdated !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The source has insufficient stock or was concurrently modified; refresh and retry.', 409);
            }

            // Destination: CAS up.
            $destinationUpdated = DB::table('inventory_items')
                ->where('tenant_id', $destination->tenant_id)
                ->where('id', $destination->getKey())
                ->where('lock_version', $destination->lock_version)
                ->update([
                    'quantity_on_hand' => DB::raw('quantity_on_hand + '.$quantity),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);

            if ($destinationUpdated !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The destination item was concurrently modified; refresh and retry.', 409);
            }

            $transfer = InventoryTransfer::query()->create([
                'tenant_id' => (string) $context->tenantId(),
                'facility_id' => $source->facility_id,
                'destination_facility_id' => $destinationFacility->getKey(),
                'inventory_item_id' => $source->getKey(),
                'medication_id' => $source->medication_id,
                'quantity' => $quantity,
                'reason' => $reason,
                'dispatched_by' => $userId,
                'dispatched_at' => now(),
                'received_by' => $userId,
                'received_at' => now(),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // Paired ledger rows — stock truth on both sides.
            InventoryMovement::query()->create([
                'tenant_id' => $source->tenant_id,
                'facility_id' => $source->facility_id,
                'inventory_item_id' => $source->getKey(),
                'movement_type' => InventoryMovement::TYPE_TRANSFER,
                'quantity_delta' => -$quantity,
                'reason' => $reason,
                'inventory_transfer_id' => $transfer->getKey(),
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);
            InventoryMovement::query()->create([
                'tenant_id' => $destination->tenant_id,
                'facility_id' => $destination->facility_id,
                'inventory_item_id' => $destination->getKey(),
                'movement_type' => InventoryMovement::TYPE_TRANSFER,
                'quantity_delta' => $quantity,
                'reason' => $reason,
                'inventory_transfer_id' => $transfer->getKey(),
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);

            return $transfer;
        });

        $this->audit->record('inventory.transferred', 'inventory_transfer', $transfer->getKey(), [
            'sourceFacilityId' => $source->facility_id,
            'destinationFacilityId' => $destinationFacility->getKey(),
            'medicationId' => $source->medication_id,
            'quantity' => $quantity,
        ], $request);

        return Envelope::success(data: [
            'id' => $transfer->getKey(),
            'sourceFacilityId' => $transfer->facility_id,
            'destinationFacilityId' => $transfer->destination_facility_id,
            'medicationId' => $transfer->medication_id,
            'quantity' => $transfer->quantity,
            'reason' => $transfer->reason,
            'transferredAt' => $transfer->received_at?->toIso8601String(),
        ], status: 201, request: $request);
    }

    /**
     * POST /inventory-items/{inventoryItem}/adjustment-requests — the
     * APPROVAL-GATED adjustment path (PRODUCT_REQUIREMENTS §6.15.5,
     * ROADMAP Phase 14 acceptance "adjustments approval-gated"): a signed
     * delta with a mandatory reason is requested; an approver (never the
     * requester) applies it. The pharmacist's immediate signed correction
     * (POST .../adjust) remains unchanged.
     */
    public function storeAdjustmentRequest(StoreAdjustmentRequest $request, InventoryItem $inventoryItem): JsonResponse
    {
        AccessCheck::scoped($inventoryItem, write: true);

        $context = TenantContext::current();

        $adjustmentRequest = InventoryAdjustmentRequest::query()->create([
            'tenant_id' => $inventoryItem->tenant_id,
            'facility_id' => $inventoryItem->facility_id,
            'inventory_item_id' => $inventoryItem->getKey(),
            'quantity_delta' => (int) $request->validated('quantityDelta'),
            'reason' => $request->validated('reason'),
            'status' => InventoryAdjustmentRequest::STATUS_REQUESTED,
            'requested_by' => $context->user?->getKey(),
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
            'updated_by' => $context->user?->getKey(),
        ]);

        $this->audit->record('inventory.adjustment_requested', 'inventory_adjustment_request', $adjustmentRequest->getKey(), [
            'inventoryItemId' => $inventoryItem->getKey(),
            'quantityDelta' => $adjustmentRequest->quantity_delta,
        ], $request);

        return Envelope::success(data: $this->presentAdjustmentRequest($adjustmentRequest), status: 201, request: $request);
    }

    /**
     * GET /inventory-items/{inventoryItem}/adjustment-requests — the
     * requests against one item, oldest first.
     */
    public function adjustmentRequests(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        AccessCheck::scoped($inventoryItem, write: false);

        $requests = InventoryAdjustmentRequest::query()
            ->where('tenant_id', $inventoryItem->tenant_id)
            ->where('inventory_item_id', $inventoryItem->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (InventoryAdjustmentRequest $r): array => $this->presentAdjustmentRequest($r))
            ->values();

        return Envelope::success(data: $requests, request: $request);
    }

    /**
     * POST /inventory-adjustment-requests/{request}/approve — the financial
     * gate: applies the requested delta atomically (item CAS + ledger row)
     * exactly like the pharmacist's immediate adjustment. Approver must
     * differ from the requester; CAS on (status, lock_version) makes a
     * duplicate/concurrent approval resolve to exactly one winner.
     */
    public function approveAdjustmentRequest(Request $request, InventoryAdjustmentRequest $adjustmentRequest): JsonResponse
    {
        AccessCheck::scoped($adjustmentRequest, write: true);

        $context = TenantContext::current();
        $approverId = $context->user?->getKey();

        $item = InventoryItem::query()->where('tenant_id', $adjustmentRequest->tenant_id)->where('id', $adjustmentRequest->inventory_item_id)->firstOrFail();

        DB::transaction(function () use ($adjustmentRequest, $item, $approverId): void {
            if ($adjustmentRequest->status !== InventoryAdjustmentRequest::STATUS_REQUESTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a requested adjustment can be approved.', 409);
            }

            if ($approverId !== null && $adjustmentRequest->requested_by === $approverId) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot approve their own adjustment request.', 403);
            }

            $delta = $adjustmentRequest->quantity_delta;

            $updated = DB::table('inventory_items')
                ->where('tenant_id', $item->tenant_id)
                ->where('id', $item->getKey())
                ->where('lock_version', $item->lock_version)
                ->where('quantity_on_hand', '>=', -$delta)
                ->update([
                    'quantity_on_hand' => DB::raw('quantity_on_hand + '.$delta),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $approverId,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This adjustment would drive stock negative or the item was concurrently modified; refresh and retry.', 409);
            }

            InventoryMovement::query()->create([
                'tenant_id' => $adjustmentRequest->tenant_id,
                'facility_id' => $adjustmentRequest->facility_id,
                'inventory_item_id' => $item->getKey(),
                'movement_type' => InventoryMovement::TYPE_ADJUSTMENT,
                'quantity_delta' => $delta,
                'reason' => $adjustmentRequest->reason.' (approved adjustment request '.$adjustmentRequest->getKey().')',
                'occurred_at' => now(),
                'created_by' => $approverId,
            ]);

            $affected = DB::table('inventory_adjustment_requests')
                ->where('tenant_id', $adjustmentRequest->tenant_id)
                ->where('id', $adjustmentRequest->getKey())
                ->where('status', InventoryAdjustmentRequest::STATUS_REQUESTED)
                ->where('lock_version', $adjustmentRequest->lock_version)
                ->update([
                    'status' => InventoryAdjustmentRequest::STATUS_APPROVED,
                    'approved_by' => $approverId,
                    'approved_at' => now(),
                    'lock_version' => $adjustmentRequest->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This adjustment request was changed by another approval. Reload and retry.', 409);
            }
        });

        $approved = $adjustmentRequest->refresh();

        $this->audit->record('inventory.adjustment_approved', 'inventory_adjustment_request', $approved->getKey(), [
            'inventoryItemId' => $approved->inventory_item_id,
            'quantityDelta' => $approved->quantity_delta,
            'newQuantityOnHand' => $item->fresh()->quantity_on_hand,
        ], $request);

        return Envelope::success(data: $this->presentAdjustmentRequest($approved), request: $request);
    }

    /**
     * POST /inventory-adjustment-requests/{request}/reject — approver
     * declines with a required reason. Terminal; CAS-guarded.
     */
    public function rejectAdjustmentRequest(RejectAdjustmentRequest $request, InventoryAdjustmentRequest $adjustmentRequest): JsonResponse
    {
        AccessCheck::scoped($adjustmentRequest, write: true);

        $context = TenantContext::current();
        $rejectedBy = $context->user?->getKey();

        DB::transaction(function () use ($adjustmentRequest, $rejectedBy, $request): void {
            if ($adjustmentRequest->status !== InventoryAdjustmentRequest::STATUS_REQUESTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a requested adjustment can be rejected.', 409);
            }

            if ($rejectedBy !== null && $adjustmentRequest->requested_by === $rejectedBy) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'The requester cannot reject their own adjustment request.', 403);
            }

            $affected = DB::table('inventory_adjustment_requests')
                ->where('tenant_id', $adjustmentRequest->tenant_id)
                ->where('id', $adjustmentRequest->getKey())
                ->where('status', InventoryAdjustmentRequest::STATUS_REQUESTED)
                ->where('lock_version', $adjustmentRequest->lock_version)
                ->update([
                    'status' => InventoryAdjustmentRequest::STATUS_REJECTED,
                    'rejected_by' => $rejectedBy,
                    'rejection_reason' => $request->validated('rejectionReason'),
                    'rejected_at' => now(),
                    'lock_version' => $adjustmentRequest->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This adjustment request was changed by another actor. Reload and retry.', 409);
            }
        });

        $rejected = $adjustmentRequest->refresh();

        $this->audit->record('inventory.adjustment_rejected', 'inventory_adjustment_request', $rejected->getKey(), [
            'inventoryItemId' => $rejected->inventory_item_id,
            'quantityDelta' => $rejected->quantity_delta,
        ], $request);

        return Envelope::success(data: $this->presentAdjustmentRequest($rejected), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAdjustmentRequest(InventoryAdjustmentRequest $request): array
    {
        return [
            'id' => $request->getKey(),
            'inventoryItemId' => $request->inventory_item_id,
            'quantityDelta' => $request->quantity_delta,
            'status' => $request->status,
            'requestedBy' => $request->requested_by,
            'approvedBy' => $request->approved_by,
            'approvedAt' => $request->approved_at?->toIso8601String(),
            'rejectedBy' => $request->rejected_by,
            'rejectedAt' => $request->rejected_at?->toIso8601String(),
            'lockVersion' => $request->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBatch(StockBatch $batch): array
    {
        return [
            'id' => $batch->getKey(),
            'inventoryItemId' => $batch->inventory_item_id,
            'medicationId' => $batch->medication_id,
            'batchNumber' => $batch->batch_number,
            'expiryDate' => $batch->expiry_date?->toDateString(),
            'quantityReceived' => $batch->quantity_received,
            'quantityRemaining' => $batch->quantity_remaining,
            'status' => $batch->status,
            'controlledDispenseRequiresDual' => $batch->controlled_dispense_requires_dual,
            'expiryStatus' => $batch->expiryStatus(),
            'daysToExpiry' => $batch->daysToExpiry(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(InventoryItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'facilityId' => $item->facility_id,
            'medicationId' => $item->medication_id,
            'medication' => $item->medication ? [
                'id' => $item->medication->getKey(),
                'genericName' => $item->medication->generic_name,
                'brandName' => $item->medication->brand_name,
                'strength' => $item->medication->strength,
                'form' => $item->medication->form,
                'unit' => $item->medication->unit,
                'isControlled' => $item->medication->is_controlled,
                'priceMinor' => $item->medication->price_minor,
                'currency' => $item->medication->currency,
            ] : null,
            'quantityOnHand' => $item->quantity_on_hand,
            'reorderLevel' => $item->reorder_level,
            'lockVersion' => $item->lock_version,
        ];
    }
}
