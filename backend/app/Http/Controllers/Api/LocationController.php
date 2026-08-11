<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Requests\Location\UpdateLocationRequest;
use App\Models\Location;
use App\Models\Organization;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Locations (DATABASE.md §3.9): non-bed physical places — waiting areas,
 * stores, nursing stations, procedure areas.
 */
final class LocationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Location::query()
            ->where('tenant_id', $organization->getKey())
            ->orderBy('name');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $locations = $query->get(['id', 'facility_id', 'branch_id', 'name', 'code', 'type', 'status'])
            ->map(fn (Location $location): array => [
                'id' => $location->getKey(),
                'facilityId' => $location->facility_id,
                'branchId' => $location->branch_id,
                'name' => $location->name,
                'code' => $location->code,
                'type' => $location->type,
                'status' => $location->status,
            ])
            ->values();

        return Envelope::success(data: $locations, request: $request);
    }

    public function store(StoreLocationRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $location = Location::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility->getKey(),
            'branch_id' => FacilityScope::resolveBranch($request->validated('branchId'), $organization->getKey(), $facility->getKey()),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'type' => $request->validated('type'),
            'status' => $request->validated('status', 'active'),
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'location.created',
            'location',
            $location->getKey(),
            ['code' => $location->code, 'name' => $location->name, 'type' => $location->type, 'facilityId' => $location->facility_id],
            $request,
        );

        return Envelope::success(
            data: self::present($location),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/locations/'.$location->getKey(),
            ],
        );
    }

    public function show(Request $request, Location $location): JsonResponse
    {
        AccessCheck::scoped($location, write: false);

        return Envelope::success(data: self::present($location), request: $request);
    }

    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        AccessCheck::scoped($location, write: true);

        $changes = [];
        foreach (['name', 'code', 'type', 'status'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = [$location->getAttribute($field), $request->validated($field)];
                $location->setAttribute($field, $request->validated($field));
            }
        }

        if ($request->has('branchId')) {
            $changes['branchId'] = [$location->branch_id, $request->validated('branchId')];
            $location->branch_id = FacilityScope::resolveBranch(
                $request->validated('branchId'),
                $location->tenant_id,
                $location->facility_id,
            );
        }

        $location->updated_by = TenantContext::current()->user?->getKey();
        $location->save();

        $this->audit->record(
            'location.updated',
            'location',
            $location->getKey(),
            ['changes' => $changes],
            $request,
        );

        return Envelope::success(data: self::present($location), request: $request);
    }

    public function destroy(Request $request, Location $location): JsonResponse
    {
        AccessCheck::scoped($location, write: true);

        $location->delete();

        $this->audit->record(
            'location.deleted',
            'location',
            $location->getKey(),
            ['code' => $location->code, 'name' => $location->name],
            $request,
        );

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Location $location): array
    {
        return [
            'id' => $location->getKey(),
            'facilityId' => $location->facility_id,
            'branchId' => $location->branch_id,
            'name' => $location->name,
            'code' => $location->code,
            'type' => $location->type,
            'status' => $location->status,
        ];
    }
}
