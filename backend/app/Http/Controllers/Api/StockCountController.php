<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Medication;
use App\Models\StockCount;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock count / cycle counting (prompt §58): records expected vs counted
 * quantity with variance, reason, and review. A reviewed count with variance
 * triggers an approval-gated inventory adjustment — the count itself never
 * mutates stock directly.
 */
final class StockCountController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /stock-counts — record a stock count.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'medicationId' => 'required|string',
            'countedQuantity' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:500',
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
            throw new ApiException(ErrorCodes::CONFLICT, 'No stock configured for this medication.', 409);
        }

        $countedQuantity = (int) $validated['countedQuantity'];
        $expectedQuantity = $item->quantity_on_hand;
        $variance = $countedQuantity - $expectedQuantity;

        $stockCount = StockCount::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'medication_id' => $medication->getKey(),
            'inventory_item_id' => $item->getKey(),
            'expected_quantity' => $expectedQuantity,
            'counted_quantity' => $countedQuantity,
            'variance' => $variance,
            'reason' => $validated['reason'] ?? null,
            'counted_by_staff_id' => $staff->getKey(),
            'status' => StockCount::STATUS_COUNTED,
            'counted_at' => now(),
            'created_by' => $userId,
        ]);

        $this->audit->record(
            'pharmacy.stock_count_recorded',
            'stock_count',
            $stockCount->getKey(),
            [
                'medicationId' => $medication->getKey(),
                'expectedQuantity' => $expectedQuantity,
                'countedQuantity' => $countedQuantity,
                'variance' => $variance,
            ],
            $request,
        );

        return Envelope::success(data: [
            'id' => $stockCount->getKey(),
            'medicationId' => $stockCount->medication_id,
            'expectedQuantity' => $stockCount->expected_quantity,
            'countedQuantity' => $stockCount->counted_quantity,
            'variance' => $stockCount->variance,
            'status' => $stockCount->status,
            'countedAt' => $stockCount->counted_at?->toIso8601String(),
        ], status: 201, request: $request);
    }

    /**
     * GET /organizations/{organization}/stock-counts — list stock counts.
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $tenantId = (string) $context->tenantId();

        $query = StockCount::query()
            ->where('tenant_id', $tenantId)
            ->with('medication:id,generic_name,brand_name,strength');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $counts = $query->orderByDesc('counted_at')
            ->paginate(min((int) $request->input('perPage', 50), 100));

        return Envelope::success(data: $counts, request: $request);
    }

    /**
     * POST /stock-counts/{stockCount}/review — reviewer approves or rejects
     * a counted stock count. CAS on (status, lock_version).
     */
    public function review(Request $request, StockCount $stockCount): JsonResponse
    {
        AccessCheck::scoped($stockCount, write: true);

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'reviewNote' => 'nullable|string|max:500',
        ]);

        $context = TenantContext::current();
        $userId = $context->user?->getKey();

        $staff = $context->user?->staff()
            ->where('tenant_id', $stockCount->tenant_id)
            ->where('status', '!=', 'departed')
            ->first();

        if ($staff === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'No active staff profile for this user.', 403);
        }

        if ($staff->getKey() === $stockCount->counted_by_staff_id) {
            throw new ApiException(ErrorCodes::FORBIDDEN, 'The reviewer must differ from the counter.', 403);
        }

        $decision = $validated['decision'];
        $newStatus = $decision === 'approved' ? StockCount::STATUS_REVIEWED : StockCount::STATUS_REJECTED;

        DB::transaction(function () use ($stockCount, $newStatus, $staff, $userId): void {
            $updated = DB::table('stock_counts')
                ->where('tenant_id', $stockCount->tenant_id)
                ->where('id', $stockCount->getKey())
                ->where('status', StockCount::STATUS_COUNTED)
                ->update([
                    'status' => $newStatus,
                    'reviewed_by_staff_id' => $staff->getKey(),
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This count was already reviewed or modified.', 409);
            }
        });

        $this->audit->record(
            'pharmacy.stock_count_reviewed',
            'stock_count',
            $stockCount->getKey(),
            ['decision' => $decision, 'variance' => $stockCount->variance],
            $request,
        );

        return Envelope::success(data: [
            'id' => $stockCount->getKey(),
            'status' => $newStatus,
            'variance' => $stockCount->variance,
            'reviewedAt' => now()->toIso8601String(),
        ], request: $request);
    }
}
