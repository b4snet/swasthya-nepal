<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ward\StoreWardRequest;
use App\Http\Requests\Ward\UpdateWardRequest;
use App\Models\Organization;
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
 * Wards (DATABASE.md §3.24): clinical wards grouping rooms and beds.
 * Soft-deletable, but RESTRICT while rooms exist.
 */
final class WardController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Ward::query()
            ->where('tenant_id', $organization->getKey())
            ->orderBy('name');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $wards = $query->get(['id', 'facility_id', 'branch_id', 'name', 'code', 'ward_type', 'status'])
            ->map(fn (Ward $ward): array => [
                'id' => $ward->getKey(),
                'facilityId' => $ward->facility_id,
                'branchId' => $ward->branch_id,
                'name' => $ward->name,
                'code' => $ward->code,
                'wardType' => $ward->ward_type,
                'status' => $ward->status,
            ])
            ->values();

        return Envelope::success(data: $wards, request: $request);
    }

    public function store(StoreWardRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $ward = Ward::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility->getKey(),
            'branch_id' => FacilityScope::resolveBranch($request->validated('branchId'), $organization->getKey(), $facility->getKey()),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'ward_type' => $request->validated('wardType'),
            'status' => $request->validated('status', 'active'),
            'settings' => $request->validated('settings', []),
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'ward.created',
            'ward',
            $ward->getKey(),
            ['code' => $ward->code, 'name' => $ward->name, 'wardType' => $ward->ward_type, 'facilityId' => $ward->facility_id],
            $request,
        );

        return Envelope::success(
            data: self::present($ward),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/wards/'.$ward->getKey(),
            ],
        );
    }

    public function show(Request $request, Ward $ward): JsonResponse
    {
        AccessCheck::scoped($ward, write: false);

        return Envelope::success(data: self::present($ward), request: $request);
    }

    public function update(UpdateWardRequest $request, Ward $ward): JsonResponse
    {
        AccessCheck::scoped($ward, write: true);

        $changes = [];
        foreach (['name', 'code', 'ward_type', 'status'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = [$ward->getAttribute($field), $request->validated($field)];
                $ward->setAttribute($field, $request->validated($field));
            }
        }

        if ($request->has('branchId')) {
            $changes['branchId'] = [$ward->branch_id, $request->validated('branchId')];
            $ward->branch_id = FacilityScope::resolveBranch(
                $request->validated('branchId'),
                $ward->tenant_id,
                $ward->facility_id,
            );
        }

        if ($request->has('settings')) {
            $changes['settings'] = [null, $request->validated('settings')];
            $ward->settings = $request->validated('settings');
        }

        $ward->updated_by = TenantContext::current()->user?->getKey();
        $ward->save();

        $this->audit->record(
            'ward.updated',
            'ward',
            $ward->getKey(),
            ['changes' => $changes],
            $request,
        );

        return Envelope::success(data: self::present($ward), request: $request);
    }

    public function destroy(Request $request, Ward $ward): JsonResponse
    {
        AccessCheck::scoped($ward, write: true);

        // Soft delete would not trip the RESTRICT FK — guard explicitly so a
        // ward with clinical capacity (rooms) can never be removed
        // (DATABASE.md §3.24).
        if ($ward->rooms()->exists()) {
            return Envelope::error(
                'CONFLICT',
                'This ward cannot be deleted while rooms reference it.',
                409,
                request: $request,
            );
        }

        try {
            $ward->delete();
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23503') {
                return Envelope::error(
                    'CONFLICT',
                    'This ward cannot be deleted while rooms reference it.',
                    409,
                    request: $request,
                );
            }

            throw $exception;
        }

        $this->audit->record(
            'ward.deleted',
            'ward',
            $ward->getKey(),
            ['code' => $ward->code, 'name' => $ward->name],
            $request,
        );

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Ward $ward): array
    {
        return [
            'id' => $ward->getKey(),
            'facilityId' => $ward->facility_id,
            'branchId' => $ward->branch_id,
            'name' => $ward->name,
            'code' => $ward->code,
            'wardType' => $ward->ward_type,
            'status' => $ward->status,
        ];
    }
}
