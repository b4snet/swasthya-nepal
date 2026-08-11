<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bed\StoreBedRequest;
use App\Http\Requests\Bed\UpdateBedRequest;
use App\Models\Bed;
use App\Models\Organization;
use App\Models\Room;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\BedStatus;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Beds (DATABASE.md §3.26): the allocatable unit of inpatient capacity.
 *
 * Status is a state machine validated by BedStatus (DATABASE.md §0.5) and
 * every change is audited with the from→to transition. Updates are
 * optimistic-locked on lock_version (DATABASE.md §0.7): a stale client wins
 * 409 LOCK_CONFLICT, never a silent overwrite. Beds are never deleted.
 */
final class BedController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Bed::query()
            ->with('room:id,code,name,ward_id')
            ->where('tenant_id', $organization->getKey())
            ->orderBy('bed_code');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $beds = $query->get()
            ->map(fn (Bed $bed): array => [
                'id' => $bed->getKey(),
                'facilityId' => $bed->facility_id,
                'branchId' => $bed->branch_id,
                'roomId' => $bed->room_id,
                'room' => $bed->room ? ['id' => $bed->room->getKey(), 'code' => $bed->room->code, 'name' => $bed->room->name] : null,
                'bedCode' => $bed->bed_code,
                'status' => $bed->status,
                'lockVersion' => $bed->lock_version,
            ])
            ->values();

        return Envelope::success(data: $beds, request: $request);
    }

    public function store(StoreBedRequest $request, Room $room): JsonResponse
    {
        AccessCheck::scoped($room, write: true);

        $context = TenantContext::current();

        $bed = Bed::query()->create([
            'tenant_id' => $room->tenant_id,
            'facility_id' => $room->facility_id,
            'branch_id' => FacilityScope::resolveBranch($request->validated('branchId'), $room->tenant_id, $room->facility_id),
            'room_id' => $room->getKey(),
            'bed_code' => $request->validated('bedCode'),
            'status' => $request->validated('status', BedStatus::AVAILABLE),
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'bed.created',
            'bed',
            $bed->getKey(),
            [
                'bedCode' => $bed->bed_code,
                'roomId' => $bed->room_id,
                'facilityId' => $bed->facility_id,
                'status' => $bed->status,
            ],
            $request,
        );

        return Envelope::success(
            data: self::present($bed),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/beds/'.$bed->getKey(),
            ],
        );
    }

    public function show(Request $request, Bed $bed): JsonResponse
    {
        AccessCheck::scoped($bed, write: false);

        return Envelope::success(data: self::present($bed), request: $request);
    }

    public function update(UpdateBedRequest $request, Bed $bed): JsonResponse
    {
        AccessCheck::scoped($bed, write: true);

        $to = $request->validated('status');
        $clientVersion = (int) $request->validated('lockVersion');

        if (! BedStatus::canTransition($bed->status, $to)) {
            return Envelope::error(
                'VALIDATION_ERROR',
                BedStatus::rejectionReason($to, $bed->status),
                422,
                request: $request,
            );
        }

        // Optimistic lock: the state change is atomic — only the caller who
        // holds the latest lock_version wins (DATABASE.md §0.7).
        $context = TenantContext::current();
        $affected = DB::table('beds')
            ->where('id', $bed->getKey())
            ->where('lock_version', $clientVersion)
            ->update([
                'status' => $to,
                'lock_version' => $bed->lock_version + 1,
                'updated_by' => $context->user?->getKey(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return Envelope::error(
                'LOCK_CONFLICT',
                'This bed was changed by someone else. Reload and retry.',
                409,
                request: $request,
            );
        }

        $from = $bed->status;
        $bed->status = $to;
        $bed->lock_version += 1;

        $this->audit->record(
            'bed.status.changed',
            'bed',
            $bed->getKey(),
            ['from' => $from, 'to' => $to, 'lockVersion' => $bed->lock_version],
            $request,
        );

        return Envelope::success(data: self::present($bed), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Bed $bed): array
    {
        return [
            'id' => $bed->getKey(),
            'facilityId' => $bed->facility_id,
            'branchId' => $bed->branch_id,
            'roomId' => $bed->room_id,
            'bedCode' => $bed->bed_code,
            'status' => $bed->status,
            'lockVersion' => $bed->lock_version,
        ];
    }
}
