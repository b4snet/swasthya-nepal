<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Ward;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rooms (DATABASE.md §3.25). Created inside a ward — the ward is the
 * tenant/facility anchor, so a room can never be created in a facility
 * other than its ward's (the tenant-safe composite FK enforces it).
 *
 * Rate changes are financial truth for bed charges and are always audited
 * (DATABASE.md §3.25). Soft-deletable, but RESTRICT while beds exist.
 */
final class RoomController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Room::query()
            ->with('ward:id,code,name')
            ->where('tenant_id', $organization->getKey())
            ->orderBy('name');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $rooms = $query->get()
            ->map(fn (Room $room): array => [
                'id' => $room->getKey(),
                'facilityId' => $room->facility_id,
                'branchId' => $room->branch_id,
                'wardId' => $room->ward_id,
                'ward' => $room->ward ? ['id' => $room->ward->getKey(), 'code' => $room->ward->code, 'name' => $room->ward->name] : null,
                'name' => $room->name,
                'code' => $room->code,
                'roomType' => $room->room_type,
                'dailyRateMinor' => $room->daily_rate_minor,
                'currency' => $room->currency,
                'status' => $room->status,
            ])
            ->values();

        return Envelope::success(data: $rooms, request: $request);
    }

    public function store(StoreRoomRequest $request, Ward $ward): JsonResponse
    {
        AccessCheck::scoped($ward, write: true);

        $context = TenantContext::current();

        $room = Room::query()->create([
            'tenant_id' => $ward->tenant_id,
            'facility_id' => $ward->facility_id,
            'branch_id' => FacilityScope::resolveBranch($request->validated('branchId'), $ward->tenant_id, $ward->facility_id),
            'ward_id' => $ward->getKey(),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'room_type' => $request->validated('roomType'),
            'daily_rate_minor' => $request->validated('dailyRateMinor'),
            'currency' => $request->validated('currency'),
            'status' => $request->validated('status', 'active'),
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'room.created',
            'room',
            $room->getKey(),
            [
                'code' => $room->code,
                'name' => $room->name,
                'wardId' => $room->ward_id,
                'facilityId' => $room->facility_id,
                'roomType' => $room->room_type,
                'dailyRateMinor' => $room->daily_rate_minor,
                'currency' => $room->currency,
            ],
            $request,
        );

        return Envelope::success(
            data: self::present($room),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/rooms/'.$room->getKey(),
            ],
        );
    }

    public function show(Request $request, Room $room): JsonResponse
    {
        AccessCheck::scoped($room, write: false);

        return Envelope::success(data: self::present($room), request: $request);
    }

    public function update(UpdateRoomRequest $request, Room $room): JsonResponse
    {
        AccessCheck::scoped($room, write: true);

        $changes = [];
        foreach (['name', 'code', 'room_type', 'status'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = [$room->getAttribute($field), $request->validated($field)];
                $room->setAttribute($field, $request->validated($field));
            }
        }

        foreach (['dailyRateMinor' => 'daily_rate_minor', 'currency' => 'currency'] as $input => $field) {
            if ($request->has($input)) {
                $changes[$field] = [$room->getAttribute($field), $request->validated($input)];
                $room->setAttribute($field, $request->validated($input));
            }
        }

        if ($request->has('branchId')) {
            $changes['branchId'] = [$room->branch_id, $request->validated('branchId')];
            $room->branch_id = FacilityScope::resolveBranch(
                $request->validated('branchId'),
                $room->tenant_id,
                $room->facility_id,
            );
        }

        $room->updated_by = TenantContext::current()->user?->getKey();
        $room->save();

        $this->audit->record(
            'room.updated',
            'room',
            $room->getKey(),
            ['changes' => $changes],
            $request,
        );

        return Envelope::success(data: self::present($room), request: $request);
    }

    public function destroy(Request $request, Room $room): JsonResponse
    {
        AccessCheck::scoped($room, write: true);

        // Soft delete would not trip the RESTRICT FK — guard explicitly so a
        // room with beds can never be removed (DATABASE.md §3.25).
        if ($room->beds()->exists()) {
            return Envelope::error(
                'CONFLICT',
                'This room cannot be deleted while beds reference it.',
                409,
                request: $request,
            );
        }

        try {
            $room->delete();
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23503') {
                return Envelope::error(
                    'CONFLICT',
                    'This room cannot be deleted while beds reference it.',
                    409,
                    request: $request,
                );
            }

            throw $exception;
        }

        $this->audit->record(
            'room.deleted',
            'room',
            $room->getKey(),
            ['code' => $room->code, 'name' => $room->name],
            $request,
        );

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Room $room): array
    {
        return [
            'id' => $room->getKey(),
            'facilityId' => $room->facility_id,
            'branchId' => $room->branch_id,
            'wardId' => $room->ward_id,
            'name' => $room->name,
            'code' => $room->code,
            'roomType' => $room->room_type,
            'dailyRateMinor' => $room->daily_rate_minor,
            'currency' => $room->currency,
            'status' => $room->status,
        ];
    }
}
