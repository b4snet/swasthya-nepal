<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Facility\StoreFacilityRequest;
use App\Models\Organization;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Facilities (API_CONTRACTS.md §21.3).
 *
 *  - index: facilities of an organization the caller belongs to; a
 *    facility-scoped principal sees only their own facility (TENANCY.md §7).
 *  - show: single facility, scope-checked (404 outside scope for reads).
 *  - store: org admin creates a facility inside their tenant; every creation
 *    is audited and carries X-Audit-Event-Id (API_CONTRACTS.md §16).
 */
final class FacilityController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = $organization->facilities()->where('status', 'active');

        // Facility-scoped principals see exactly their facility.
        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('id', $context->facilityId());
        }

        $facilities = $query->get(['id', 'name', 'code', 'status', 'timezone'])
            ->map(fn ($facility): array => [
                'id' => $facility->id,
                'name' => $facility->name,
                'code' => $facility->code,
                'status' => $facility->status,
                'timezone' => $facility->timezone,
            ])
            ->values();

        return Envelope::success(data: $facilities, request: $request);
    }

    public function show(Request $request, string $facility): JsonResponse
    {
        $facilityModel = AccessCheck::facility($facility, write: false);

        return Envelope::success(
            data: [
                'id' => $facilityModel->getKey(),
                'name' => $facilityModel->name,
                'code' => $facilityModel->code,
                'status' => $facilityModel->status,
                'timezone' => $facilityModel->timezone,
                'address' => $facilityModel->address,
            ],
            request: $request,
        );
    }

    public function store(StoreFacilityRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();

        $facility = $organization->facilities()->create([
            'tenant_id' => $organization->getKey(),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'timezone' => $request->validated('timezone'),
            'status' => 'active',
            'address' => [],
            'settings' => [],
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'facility.created',
            'facility',
            $facility->getKey(),
            ['code' => $facility->code, 'name' => $facility->name],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $facility->getKey(),
                'name' => $facility->name,
                'code' => $facility->code,
                'status' => $facility->status,
                'timezone' => $facility->timezone,
            ],
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/facilities/'.$facility->getKey(),
            ],
        );
    }
}
