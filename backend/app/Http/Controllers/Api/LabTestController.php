<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lab\StoreLabTestRequest;
use App\Models\LabTest;
use App\Models\Organization;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lab/radiology test catalog (DATABASE.md §3.25) — the reference for
 * ordering investigations, mirroring the formulary pattern. Catalog items
 * are tenant+facility scoped; retired tests stay referenced by order history.
 */
final class LabTestController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = LabTest::query()
            ->where('tenant_id', $organization->getKey())
            ->orderBy('name');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $tests = $query->get()
            ->map(fn (LabTest $test): array => self::present($test))
            ->values();

        return Envelope::success(data: $tests, request: $request);
    }

    public function store(StoreLabTestRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $test = LabTest::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility->getKey(),
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'category' => $request->validated('category', LabTest::CATEGORY_LABORATORY),
            'sample_type' => $request->validated('sampleType'),
            'unit' => $request->validated('unit'),
            'reference_range' => $request->validated('referenceRange'),
            'method' => $request->validated('method'),
            'status' => LabTest::STATUS_ACTIVE,
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'lab_test.created',
            'lab_test',
            $test->getKey(),
            ['code' => $test->code, 'name' => $test->name, 'category' => $test->category, 'facilityId' => $test->facility_id],
            $request,
        );

        return Envelope::success(data: self::present($test), status: 201, request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(LabTest $test): array
    {
        return [
            'id' => $test->getKey(),
            'facilityId' => $test->facility_id,
            'code' => $test->code,
            'name' => $test->name,
            'category' => $test->category,
            'sampleType' => $test->sample_type,
            'unit' => $test->unit,
            'referenceRange' => $test->reference_range,
            'method' => $test->method,
            'status' => $test->status,
        ];
    }
}
