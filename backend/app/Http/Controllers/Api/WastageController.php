<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\StockBatch;
use App\Models\Wastage;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Medication wastage (PRODUCT_REQUIREMENTS §6.7, prompt §38): records
 * medication destroyed / wasted with a traceable reason, actor, and optional
 * witness. Every wastage is an atomic transaction: CAS stock decrement +
 * ledger movement (TYPE_WASTAGE) + wastage record. Wasted stock never
 * silently re-enters available inventory.
 */
final class WastageController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /wastages — record medication wastage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'medicationId' => 'required|string',
            'quantityMinor' => 'required|integer|min:1',
            'reasonCode' => 'required|in:expired,damaged,contaminated,recalled,partial_use,other',
            'reasonNote' => 'nullable|string|max:500',
            'stockBatchId' => 'nullable|string',
            'witnessStaffId' => 'nullable|string',
        ]);

        $context = TenantContext::current();
        $tenantId = (string) $context->tenantId();
        $userId = $context->user?->getKey();

        $staff = $context->user?->staff()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'departed')
            ->first();

        if ($staff === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'No active staff profile for this user.', 403);
        }

        $facilityId = (string) $staff->facility_id;

        $medication = Medication::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('id', $validated['medicationId'])
            ->first();

        if ($medication === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Medication not found.', 404);
        }

        $item = InventoryItem::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('medication_id', $medication->getKey())
            ->first();

        if ($item === null) {
            throw new ApiException(ErrorCodes::CONFLICT, 'No stock configured for this medication at this facility.', 409);
        }

        $quantity = (int) $validated['quantityMinor'];
        $batchId = $validated['stockBatchId'] ?? null;

        $wastage = DB::transaction(function () use ($tenantId, $facilityId, $item, $medication, $quantity, $batchId, $staff, $userId, $validated): Wastage {
            $batch = null;
            if ($batchId !== null) {
                $batch = StockBatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('facility_id', $facilityId)
                    ->where('id', $batchId)
                    ->lockForUpdate()
                    ->first();

                if ($batch === null) {
                    throw new ApiException(ErrorCodes::NOT_FOUND, 'Stock batch not found.', 404);
                }

                if ($batch->quantity_remaining < $quantity) {
                    throw new ApiException(ErrorCodes::CONFLICT, 'Insufficient batch stock for wastage.', 409);
                }

                DB::table('stock_batches')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $batch->getKey())
                    ->where('lock_version', $batch->lock_version)
                    ->update([
                        'quantity_remaining' => DB::raw('quantity_remaining - '.$quantity),
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_by' => $userId,
                        'updated_at' => now(),
                    ]);
            }

            $updated = DB::table('inventory_items')
                ->where('tenant_id', $tenantId)
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
                throw new ApiException(ErrorCodes::CONFLICT, 'Insufficient stock or concurrent modification; refresh and retry.', 409);
            }

            $wastage = Wastage::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'medication_id' => $medication->getKey(),
                'inventory_item_id' => $item->getKey(),
                'stock_batch_id' => $batch?->getKey(),
                'batch_number' => $batch?->batch_number,
                'batch_expires_at' => $batch?->expiry_date?->toDateString(),
                'quantity_minor' => $quantity,
                'reason_code' => $validated['reasonCode'],
                'reason_note' => $validated['reasonNote'] ?? null,
                'wasted_by_staff_id' => $staff->getKey(),
                'witness_staff_id' => $validated['witnessStaffId'] ?? null,
                'wasted_at' => now(),
                'created_by' => $userId,
            ]);

            InventoryMovement::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'inventory_item_id' => $item->getKey(),
                'movement_type' => InventoryMovement::TYPE_WASTAGE,
                'quantity_delta' => -$quantity,
                'reason' => $medication->generic_name.' wastage: '.$validated['reasonCode'],
                'stock_batch_id' => $batch?->getKey(),
                'wastage_id' => $wastage->getKey(),
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);

            return $wastage;
        });

        $this->audit->record(
            'pharmacy.wastage_recorded',
            'wastage',
            $wastage->getKey(),
            [
                'medicationId' => $wastage->medication_id,
                'quantityMinor' => $wastage->quantity_minor,
                'reasonCode' => $wastage->reason_code,
                'batchId' => $wastage->stock_batch_id,
                'wastedByStaffId' => $wastage->wasted_by_staff_id,
            ],
            $request,
        );

        return Envelope::success(data: [
            'id' => $wastage->getKey(),
            'medicationId' => $wastage->medication_id,
            'batchNumber' => $wastage->batch_number,
            'quantityMinor' => $wastage->quantity_minor,
            'reasonCode' => $wastage->reason_code,
            'wastedAt' => $wastage->wasted_at?->toIso8601String(),
        ], status: 201, request: $request);
    }

    /**
     * GET /organizations/{organization}/wastages — list wastage records.
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $tenantId = (string) $context->tenantId();

        $query = Wastage::query()
            ->where('tenant_id', $tenantId)
            ->with('medication:id,generic_name,brand_name,strength');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $wastages = $query->orderByDesc('wasted_at')
            ->paginate(min((int) $request->input('perPage', 50), 100));

        return Envelope::success(data: $wastages, request: $request);
    }
}
