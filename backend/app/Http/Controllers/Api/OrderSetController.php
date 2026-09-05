<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\OrderSet;
use App\Models\OrderSetApplication;
use App\Models\OrderSetItem;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Order Set management — CRUD for versioned order-set templates and
 * application to encounters. Preserves provenance: every application
 * records the exact version used, the clinician who applied it, and
 * any modifications made to individual items.
 */
final class OrderSetController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /order-sets — list published order sets (optionally filtered by specialty).
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $query = OrderSet::query()
            ->where('tenant_id', $context->tenantId())
            ->where('status', OrderSet::STATUS_PUBLISHED)
            ->with(['items', 'owner:id,full_name']);

        if ($request->has('specialty')) {
            $query->where('specialty', $request->input('specialty'));
        }

        $sets = $query->orderBy('name')->get();

        return Envelope::success(data: $sets, request: $request);
    }

    /**
     * GET /order-sets/{orderSet} — view an order set with its items.
     */
    public function show(Request $request, OrderSet $orderSet): JsonResponse
    {
        AccessCheck::scoped($orderSet, write: false);

        $orderSet->load(['items', 'owner:id,full_name', 'reviewer:id,full_name']);

        return Envelope::success(data: $orderSet, request: $request);
    }

    /**
     * POST /order-sets — create a new draft order set.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'specialty' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.orderType' => 'required|in:lab,imaging,medication,procedure,referral',
            'items.*.serviceCode' => 'nullable|string|max:100',
            'items.*.serviceName' => 'required|string|max:255',
            'items.*.defaultQuantity' => 'nullable|integer|min:1',
            'items.*.defaultFrequency' => 'nullable|string|max:50',
            'items.*.defaultDuration' => 'nullable|string|max:50',
            'items.*.defaultInstructions' => 'nullable|string|max:1000',
            'items.*.defaultPriority' => 'sometimes|string|in:routine,urgent,stat',
            'items.*.isOptional' => 'sometimes|boolean',
        ]);

        $context = TenantContext::current();

        $orderSet = DB::transaction(function () use ($data, $context): OrderSet {
            $set = OrderSet::query()->create([
                'tenant_id' => $context->tenantId(),
                'facility_id' => $context->facilityId(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'specialty' => $data['specialty'] ?? null,
                'version' => 1,
                'status' => OrderSet::STATUS_DRAFT,
                'owner_staff_id' => $context->user?->getKey(),
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);

            $sortOrder = 0;
            foreach ($data['items'] as $item) {
                OrderSetItem::query()->create([
                    'tenant_id' => $context->tenantId(),
                    'order_set_id' => $set->getKey(),
                    'order_type' => $item['orderType'],
                    'service_code' => $item['serviceCode'] ?? null,
                    'service_name' => $item['serviceName'],
                    'default_quantity' => $item['defaultQuantity'] ?? null,
                    'default_frequency' => $item['defaultFrequency'] ?? null,
                    'default_duration' => $item['defaultDuration'] ?? null,
                    'default_instructions' => $item['defaultInstructions'] ?? null,
                    'default_priority' => $item['defaultPriority'] ?? 'routine',
                    'sort_order' => $sortOrder++,
                    'is_optional' => $item['isOptional'] ?? false,
                ]);
            }

            return $set;
        });

        $this->audit->record(
            'order_set.created',
            'order_set',
            $orderSet->getKey(),
            ['name' => $orderSet->name, 'itemCount' => $orderSet->items()->count()],
            $request,
        );

        return Envelope::success(data: $orderSet->load('items'), status: 201, request: $request);
    }

    /**
     * POST /order-sets/{orderSet}/publish — transition draft → published.
     * Requires the order set to be in draft or approved status.
     */
    public function publish(Request $request, OrderSet $orderSet): JsonResponse
    {
        AccessCheck::scoped($orderSet, write: true);

        if (! in_array($orderSet->status, [OrderSet::STATUS_DRAFT, OrderSet::STATUS_APPROVED], true)) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only a draft or approved order set can be published.', 409);
        }

        $updated = DB::table('order_sets')
            ->where('id', $orderSet->getKey())
            ->where('lock_version', $orderSet->lock_version)
            ->update([
                'status' => OrderSet::STATUS_PUBLISHED,
                'published_at' => now(),
                'lock_version' => $orderSet->lock_version + 1,
                'updated_by' => TenantContext::current()->user?->getKey(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This order set was concurrently modified; refresh and retry.', 409);
        }

        $this->audit->record(
            'order_set.published',
            'order_set',
            $orderSet->getKey(),
            ['name' => $orderSet->name, 'version' => $orderSet->version],
            $request,
        );

        return Envelope::success(data: $orderSet->fresh(), request: $request);
    }

    /**
     * POST /order-sets/{orderSet}/retire — transition published → retired.
     */
    public function retire(Request $request, OrderSet $orderSet): JsonResponse
    {
        AccessCheck::scoped($orderSet, write: true);

        if ($orderSet->status !== OrderSet::STATUS_PUBLISHED) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only a published order set can be retired.', 409);
        }

        $updated = DB::table('order_sets')
            ->where('id', $orderSet->getKey())
            ->where('lock_version', $orderSet->lock_version)
            ->update([
                'status' => OrderSet::STATUS_RETIRED,
                'retired_at' => now(),
                'lock_version' => $orderSet->lock_version + 1,
                'updated_by' => TenantContext::current()->user?->getKey(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This order set was concurrently modified; refresh and retry.', 409);
        }

        $this->audit->record(
            'order_set.retired',
            'order_set',
            $orderSet->getKey(),
            ['name' => $orderSet->name],
            $request,
        );

        return Envelope::success(data: $orderSet->fresh(), request: $request);
    }

    /**
     * POST /encounters/{encounter}/order-sets/{orderSet}/apply — apply a
     * published order set to an encounter. Records provenance and allows
     * per-item modifications.
     */
    public function apply(Request $request, Encounter $encounter, OrderSet $orderSet): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        if ($orderSet->status !== OrderSet::STATUS_PUBLISHED) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only a published order set can be applied.', 409);
        }

        $data = $request->validate([
            'modifications' => 'sometimes|array',
            'modifications.*.orderSetItemId' => 'required|uuid',
            'modifications.*.overrideFrequency' => 'nullable|string|max:50',
            'modifications.*.overrideDuration' => 'nullable|string|max:50',
            'modifications.*.overrideInstructions' => 'nullable|string|max:1000',
            'modifications.*.overridePriority' => 'nullable|string|in:routine,urgent,stat',
            'skipOptionalItems' => 'sometimes|boolean',
        ]);

        $context = TenantContext::current();

        $application = DB::transaction(function () use ($encounter, $orderSet, $data, $context): OrderSetApplication {
            $items = $orderSet->items()->get();
            $skipOptional = $data['skipOptionalItems'] ?? false;
            $modifications = collect($data['modifications'] ?? [])
                ->keyBy('orderSetItemId');

            $appliedItems = [];
            $createdOrders = [];

            foreach ($items as $item) {
                if ($skipOptional && $item->is_optional) {
                    continue;
                }

                $mod = $modifications->get($item->getKey());

                $appliedItems[] = [
                    'orderSetItemId' => $item->getKey(),
                    'orderType' => $item->order_type,
                    'serviceName' => $item->service_name,
                    'modified' => $mod !== null,
                ];

                // Record the order creation in provenance
                $createdOrders[] = [
                    'orderType' => $item->order_type,
                    'serviceCode' => $item->service_code,
                    'serviceName' => $item->service_name,
                    'frequency' => $mod['overrideFrequency'] ?? $item->default_frequency,
                    'duration' => $mod['overrideDuration'] ?? $item->default_duration,
                    'priority' => $mod['overridePriority'] ?? $item->default_priority,
                ];
            }

            return OrderSetApplication::query()->create([
                'tenant_id' => $encounter->tenant_id,
                'facility_id' => $encounter->facility_id,
                'order_set_id' => $orderSet->getKey(),
                'order_set_version' => $orderSet->version,
                'encounter_id' => $encounter->getKey(),
                'applied_by_staff_id' => $context->user?->getKey(),
                'applied_at' => now(),
                'item_count' => count($appliedItems),
                'modified_items' => $appliedItems,
                'created_orders' => $createdOrders,
            ]);
        });

        $this->audit->record(
            'order_set.applied',
            'order_set_application',
            $application->getKey(),
            [
                'encounterId' => $encounter->getKey(),
                'orderSetId' => $orderSet->getKey(),
                'version' => $orderSet->version,
                'itemCount' => $application->item_count,
            ],
            $request,
        );

        return Envelope::success(data: $application, status: 201, request: $request);
    }

    /**
     * GET /encounters/{encounter}/order-set-applications — list order sets applied to this encounter.
     */
    public function forEncounter(Request $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: false);

        $applications = OrderSetApplication::query()
            ->where('encounter_id', $encounter->getKey())
            ->with('orderSet:id,name,version,specialty')
            ->orderByDesc('applied_at')
            ->get();

        return Envelope::success(data: $applications, request: $request);
    }
}
