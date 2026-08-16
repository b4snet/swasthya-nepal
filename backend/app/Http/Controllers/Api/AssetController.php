<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\CompleteWorkOrderRequest;
use App\Http\Requests\Assets\OpenWorkOrderRequest;
use App\Http\Requests\Assets\StoreAssetCategoryRequest;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Requests\Assets\StoreIotReadingRequest;
use App\Http\Requests\Assets\StoreMaintenanceScheduleRequest;
use App\Http\Requests\Assets\TransferAssetRequest;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetTransfer;
use App\Models\IotReading;
use App\Models\MaintenanceSchedule;
use App\Models\Staff;
use App\Models\WorkOrder;
use App\Services\HrAssetsService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 19 — Assets (PRODUCT_REQUIREMENTS §6.18, DATABASE.md §3.46):
 * asset categories, the asset register with an explicit lifecycle
 * (procured → deployed → under_repair → retired), append-only transfers,
 * maintenance schedules, work orders with HONEST downtime tracking and
 * provable certification, and the RFID/IoT-ready reading model.
 *
 * Downtime truthfulness is a safety criterion: an asset with an open
 * downtime work order is under_repair — a machine listed as available while
 * down is a planning hazard. Nothing here fakes a device integration.
 */
final class AssetController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HrAssetsService $assets,
    ) {}

    /**
     * GET asset-categories — the category catalog within scope.
     */
    public function categories(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $categories = AssetCategory::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderBy('name')
            ->get()
            ->map(fn (AssetCategory $c): array => [
                'id' => $c->getKey(),
                'code' => $c->code,
                'name' => $c->name,
                'status' => $c->status,
            ])
            ->values();

        return Envelope::success(data: $categories, request: $request);
    }

    /**
     * POST asset-categories — create an asset category.
     */
    public function storeCategory(StoreAssetCategoryRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $this->resolveFacilityId($context);

        $category = AssetCategory::query()->create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => $facilityId,
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'status' => $request->validated('status', AssetCategory::STATUS_ACTIVE),
            'created_by' => $this->currentStaffId($context, $facilityId),
        ]);

        $this->audit->record('asset_category.created', 'asset_category', $category->getKey(), [
            'code' => $category->code,
        ], $request);

        return Envelope::success(data: [
            'id' => $category->getKey(),
            'code' => $category->code,
            'name' => $category->name,
            'status' => $category->status,
        ], status: 201, request: $request);
    }

    /**
     * GET assets — the asset register within scope (lifecycle visible).
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $assets = Asset::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->when($request->query('lifecycleStatus') !== null, fn ($q) => $q->where('lifecycle_status', $request->query('lifecycleStatus')))
            ->with('category', 'currentLocation')
            ->orderBy('name')
            ->get()
            ->map(fn (Asset $a): array => self::presentAsset($a))
            ->values();

        return Envelope::success(data: $assets, request: $request);
    }

    /**
     * POST assets — register an asset (lifecycle: procured).
     */
    public function store(StoreAssetRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $this->resolveFacilityId($context);

        $category = AssetCategory::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', $facilityId)
            ->where('id', $request->validated('categoryId'))
            ->first();

        if ($category === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Asset category not found.', 404);
        }

        if ($request->validated('currentLocationId') !== null) {
            $location = DB::table('locations')
                ->where('tenant_id', (string) $context->tenantId())
                ->where('facility_id', $facilityId)
                ->where('id', $request->validated('currentLocationId'))
                ->first();

            if ($location === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Location not found.', 404);
            }
        }

        $asset = Asset::query()->create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => $facilityId,
            'category_id' => (string) $category->getKey(),
            'name' => $request->validated('name'),
            'serial_number' => $request->validated('serialNumber'),
            'rfid_tag' => $request->validated('rfidTag'),
            'barcode' => $request->validated('barcode'),
            'current_location_id' => $request->validated('currentLocationId'),
            'purchase_value_minor' => $request->validated('purchaseValueMinor'),
            'purchase_date' => $request->validated('purchaseDate'),
            'warranty_until' => $request->validated('warrantyUntil'),
            'lifecycle_status' => Asset::LIFECYCLE_PROCURED,
            'status' => Asset::STATUS_ACTIVE,
            'lock_version' => 0,
            'created_by' => $this->currentStaffId($context, $facilityId),
        ]);

        $this->audit->record('asset.registered', 'asset', $asset->getKey(), [
            'categoryId' => $asset->category_id,
            'lifecycleStatus' => $asset->lifecycle_status,
        ], $request);

        return Envelope::success(data: self::presentAsset($asset->load('category', 'currentLocation')), status: 201, request: $request);
    }

    /**
     * POST assets/{asset}/deploy — procured → deployed (CAS).
     */
    public function deploy(Request $request, Asset $asset): JsonResponse
    {
        AccessCheck::scoped($asset, write: true);

        $asset = $this->assets->deployAsset($asset, $this->currentStaffId(TenantContext::current(), (string) $asset->facility_id));

        $this->audit->record('asset.deployed', 'asset', $asset->getKey(), [
            'lifecycleStatus' => $asset->lifecycle_status,
        ], $request);

        return Envelope::success(data: self::presentAsset($asset->load('category', 'currentLocation')), request: $request);
    }

    /**
     * POST assets/{asset}/retire — deployed | under_repair → retired (CAS;
     * terminal).
     */
    public function retire(Request $request, Asset $asset): JsonResponse
    {
        AccessCheck::scoped($asset, write: true);

        $asset = $this->assets->retireAsset($asset, $this->currentStaffId(TenantContext::current(), (string) $asset->facility_id));

        $this->audit->record('asset.retired', 'asset', $asset->getKey(), [
            'lifecycleStatus' => $asset->lifecycle_status,
        ], $request);

        return Envelope::success(data: self::presentAsset($asset->load('category', 'currentLocation')), request: $request);
    }

    /**
     * POST assets/{asset}/transfer — move an asset to another location
     * (append-only location history + current_location_id in one tx).
     */
    public function transfer(TransferAssetRequest $request, Asset $asset): JsonResponse
    {
        AccessCheck::scoped($asset, write: true);

        $transfer = $this->assets->transferAsset(
            $asset,
            (string) $request->validated('toLocationId'),
            $this->currentStaffId(TenantContext::current(), (string) $asset->facility_id),
            $request->validated('reason'),
        );

        $this->audit->record('asset.transferred', 'asset_transfer', $transfer->getKey(), [
            'assetId' => $transfer->asset_id,
            'fromLocationId' => $transfer->from_location_id,
            'toLocationId' => $transfer->to_location_id,
        ], $request);

        $asset->refresh();

        return Envelope::success(data: [
            'transferId' => $transfer->getKey(),
            'fromLocationId' => $transfer->from_location_id,
            'toLocationId' => $transfer->to_location_id,
            'transferredAt' => $transfer->transferred_at?->toIso8601String(),
            'asset' => self::presentAsset($asset->load('category', 'currentLocation')),
        ], status: 201, request: $request);
    }

    /**
     * GET assets/{asset}/transfers — the append-only location history.
     */
    public function transfers(Request $request, Asset $asset): JsonResponse
    {
        AccessCheck::scoped($asset, write: false);

        $transfers = $asset->transfers()
            ->orderBy('transferred_at')
            ->get()
            ->map(fn (AssetTransfer $t): array => [
                'id' => $t->getKey(),
                'fromLocationId' => $t->from_location_id,
                'toLocationId' => $t->to_location_id,
                'transferredAt' => $t->transferred_at?->toIso8601String(),
                'transferredByStaffId' => $t->transferred_by_staff_id,
            ])
            ->values();

        return Envelope::success(data: $transfers, request: $request);
    }

    /**
     * GET maintenance-schedules — scheduled maintenance within scope.
     */
    public function maintenanceSchedules(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $schedules = MaintenanceSchedule::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->when($request->query('assetId') !== null, fn ($q) => $q->where('asset_id', $request->query('assetId')))
            ->orderBy('next_due_date')
            ->get()
            ->map(fn (MaintenanceSchedule $s): array => [
                'id' => $s->getKey(),
                'assetId' => $s->asset_id,
                'scheduleType' => $s->schedule_type,
                'frequencyDays' => $s->frequency_days,
                'nextDueDate' => $s->next_due_date->toDateString(),
                'lastCompletedAt' => $s->last_completed_at?->toDateString(),
                'contractRef' => $s->contract_ref,
                'status' => $s->status,
                'lockVersion' => $s->lock_version,
            ])
            ->values();

        return Envelope::success(data: $schedules, request: $request);
    }

    /**
     * POST maintenance-schedules — schedule recurring maintenance.
     */
    public function storeMaintenanceSchedule(StoreMaintenanceScheduleRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $asset = Asset::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('id', $request->validated('assetId'))
            ->first();

        if ($asset === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Asset not found.', 404);
        }

        $schedule = MaintenanceSchedule::query()->create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => (string) $asset->facility_id,
            'asset_id' => (string) $asset->getKey(),
            'schedule_type' => $request->validated('scheduleType'),
            'frequency_days' => $request->validated('frequencyDays'),
            'next_due_date' => $request->validated('nextDueDate'),
            'contract_ref' => $request->validated('contractRef'),
            'status' => $request->validated('status', MaintenanceSchedule::STATUS_ACTIVE),
            'lock_version' => 0,
            'created_by' => $this->currentStaffId($context, (string) $asset->facility_id),
        ]);

        $this->audit->record('maintenance_schedule.created', 'maintenance_schedule', $schedule->getKey(), [
            'assetId' => $schedule->asset_id,
            'scheduleType' => $schedule->schedule_type,
            'frequencyDays' => $schedule->frequency_days,
        ], $request);

        return Envelope::success(data: [
            'id' => $schedule->getKey(),
            'assetId' => $schedule->asset_id,
            'scheduleType' => $schedule->schedule_type,
            'frequencyDays' => $schedule->frequency_days,
            'nextDueDate' => $schedule->next_due_date->toDateString(),
            'contractRef' => $schedule->contract_ref,
            'status' => $schedule->status,
            'lockVersion' => $schedule->lock_version,
        ], status: 201, request: $request);
    }

    /**
     * GET work-orders — maintenance work within scope.
     */
    public function workOrders(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $orders = WorkOrder::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->when($request->query('assetId') !== null, fn ($q) => $q->where('asset_id', $request->query('assetId')))
            ->when($request->query('status') !== null, fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('opened_at')
            ->get()
            ->map(fn (WorkOrder $o): array => self::presentWorkOrder($o))
            ->values();

        return Envelope::success(data: $orders, request: $request);
    }

    /**
     * POST work-orders — open maintenance work. When downtime is tracked,
     * the asset moves to under_repair in the same transaction (honest
     * downtime — a machine listed as available while down is a hazard).
     */
    public function openWorkOrder(OpenWorkOrderRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $asset = Asset::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('id', $request->validated('assetId'))
            ->first();

        if ($asset === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Asset not found.', 404);
        }

        $order = $this->assets->openWorkOrder(
            $asset,
            $request->validated('maintenanceScheduleId'),
            $this->currentStaffId($context, (string) $asset->facility_id),
            $request->validated('downtimeStartedAt') !== null
                ? CarbonImmutable::parse($request->validated('downtimeStartedAt'))
                : null,
            $request->validated('description'),
        );

        $this->audit->record('work_order.opened', 'work_order', $order->getKey(), [
            'assetId' => $order->asset_id,
            'tracksDowntime' => $order->downtime_started_at !== null,
        ], $request);

        $asset->refresh();

        return Envelope::success(data: [
            'workOrder' => self::presentWorkOrder($order),
            'assetLifecycleStatus' => $asset->lifecycle_status,
        ], status: 201, request: $request);
    }

    /**
     * POST work-orders/{order}/complete — close maintenance work (downtime
     * closed, certification recorded, asset back to deployed).
     */
    public function completeWorkOrder(CompleteWorkOrderRequest $request, WorkOrder $workOrder): JsonResponse
    {
        AccessCheck::scoped($workOrder, write: true);

        $order = $this->assets->completeWorkOrder(
            $workOrder,
            $this->currentStaffId(TenantContext::current(), (string) $workOrder->facility_id),
            $request->validated('downtimeEndedAt') !== null
                ? CarbonImmutable::parse($request->validated('downtimeEndedAt'))
                : null,
            $request->validated('certificationRef'),
        );

        $this->audit->record('work_order.completed', 'work_order', $order->getKey(), [
            'assetId' => $order->asset_id,
            'hadDowntime' => $order->downtime_started_at !== null,
        ], $request);

        $asset = $order->asset()->first();

        return Envelope::success(data: [
            'workOrder' => self::presentWorkOrder($order),
            'assetLifecycleStatus' => $asset?->lifecycle_status,
        ], request: $request);
    }

    /**
     * POST work-orders/{order}/cancel — cancel an open work order
     * (releases any under_repair hold).
     */
    public function cancelWorkOrder(Request $request, WorkOrder $workOrder): JsonResponse
    {
        AccessCheck::scoped($workOrder, write: true);

        $order = $this->assets->cancelWorkOrder($workOrder, $this->currentStaffId(TenantContext::current(), (string) $workOrder->facility_id));

        $this->audit->record('work_order.cancelled', 'work_order', $order->getKey(), [
            'assetId' => $order->asset_id,
        ], $request);

        $asset = $order->asset()->first();

        return Envelope::success(data: [
            'workOrder' => self::presentWorkOrder($order),
            'assetLifecycleStatus' => $asset?->lifecycle_status,
        ], request: $request);
    }

    /**
     * GET assets/{asset}/iot-readings — the RFID/IoT-ready reading log
     * (append-only; device feeds arrive in Phase 3 with a real integration).
     */
    public function iotReadings(Request $request, Asset $asset): JsonResponse
    {
        AccessCheck::scoped($asset, write: false);

        $readings = $asset->iotReadings()
            ->orderByDesc('read_at')
            ->get()
            ->map(fn (IotReading $r): array => [
                'id' => $r->getKey(),
                'readingType' => $r->reading_type,
                'readingValue' => $r->reading_value,
                'tagId' => $r->tag_id,
                'readAt' => $r->read_at?->toIso8601String(),
                'source' => $r->source,
            ])
            ->values();

        return Envelope::success(data: $readings, request: $request);
    }

    /**
     * POST assets/{asset}/iot-readings — record a reading (manual source for
     * now; the model is designed for rfid/device feeds in Phase 3).
     */
    public function storeIotReading(StoreIotReadingRequest $request, Asset $asset): JsonResponse
    {
        AccessCheck::scoped($asset, write: true);

        $reading = $asset->iotReadings()->create([
            'tenant_id' => $asset->tenant_id,
            'facility_id' => $asset->facility_id,
            'asset_id' => $asset->getKey(),
            'reading_type' => $request->validated('readingType'),
            'reading_value' => $request->validated('readingValue'),
            'tag_id' => $request->validated('tagId'),
            'read_at' => $request->validated('readAt') ?? now(),
            'source' => $request->validated('source', IotReading::SOURCE_MANUAL),
            'created_by' => $this->currentStaffId(TenantContext::current(), (string) $asset->facility_id),
        ]);

        $this->audit->record('iot_reading.recorded', 'iot_reading', $reading->getKey(), [
            'assetId' => $asset->getKey(),
            'readingType' => $reading->reading_type,
        ], $request);

        return Envelope::success(data: [
            'id' => $reading->getKey(),
            'readingType' => $reading->reading_type,
            'readingValue' => $reading->reading_value,
            'tagId' => $reading->tag_id,
            'readAt' => $reading->read_at?->toIso8601String(),
            'source' => $reading->source,
        ], status: 201, request: $request);
    }

    private function resolveFacilityId(TenantContext $context): string
    {
        $facilityId = $context->facilityId();

        if ($facilityId === null) {
            throw new ApiException(ErrorCodes::FACILITY_DENIED, 'A facility context is required for this operation.', 403);
        }

        return $facilityId;
    }

    private function currentStaffId(TenantContext $context, string $facilityId): ?string
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', $facilityId)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        return $staff?->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentAsset(Asset $asset): array
    {
        return [
            'id' => $asset->getKey(),
            'categoryId' => $asset->category_id,
            'name' => $asset->name,
            'serialNumber' => $asset->serial_number,
            'rfidTag' => $asset->rfid_tag,
            'barcode' => $asset->barcode,
            'currentLocationId' => $asset->current_location_id,
            'purchaseValueMinor' => $asset->purchase_value_minor,
            'purchaseDate' => $asset->purchase_date?->toDateString(),
            'warrantyUntil' => $asset->warranty_until?->toDateString(),
            'lifecycleStatus' => $asset->lifecycle_status,
            'status' => $asset->status,
            'lockVersion' => $asset->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentWorkOrder(WorkOrder $order): array
    {
        return [
            'id' => $order->getKey(),
            'workOrderNumber' => $order->work_order_number,
            'assetId' => $order->asset_id,
            'maintenanceScheduleId' => $order->maintenance_schedule_id,
            'status' => $order->status,
            'openedAt' => $order->opened_at?->toIso8601String(),
            'openedByStaffId' => $order->opened_by_staff_id,
            'completedAt' => $order->completed_at?->toIso8601String(),
            'completedByStaffId' => $order->completed_by_staff_id,
            'downtimeStartedAt' => $order->downtime_started_at?->toIso8601String(),
            'downtimeEndedAt' => $order->downtime_ended_at?->toIso8601String(),
            'description' => $order->description,
            'certificationRef' => $order->certification_ref,
            'lockVersion' => $order->lock_version,
        ];
    }
}
