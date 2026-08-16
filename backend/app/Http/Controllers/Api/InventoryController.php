<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\AdjustInventoryRequest;
use App\Http\Requests\Pharmacy\StoreInventoryRequest;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
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
