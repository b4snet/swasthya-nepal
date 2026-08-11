<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Service\StoreServiceRequest;
use App\Http\Requests\Service\UpdateServiceRequest;
use App\Models\Organization;
use App\Models\Service;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hospital services catalog (DATABASE.md §3.16 references service_id):
 * OPD consultations, procedures, investigations — the offerings schedules
 * and appointment booking will reference.
 *
 * Rates are integer minor units, never floats (DATABASE.md §0.4). Changes
 * to charges are audited (financial truth for future billing).
 */
final class ServiceController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Service::query()
            ->with('department:id,code,name')
            ->where('tenant_id', $organization->getKey())
            ->orderBy('name');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $services = $query->get()
            ->map(fn (Service $service): array => [
                'id' => $service->getKey(),
                'facilityId' => $service->facility_id,
                'departmentId' => $service->department_id,
                'department' => $service->department ? ['id' => $service->department->getKey(), 'code' => $service->department->code, 'name' => $service->department->name] : null,
                'name' => $service->name,
                'code' => $service->code,
                'serviceType' => $service->service_type,
                'status' => $service->status,
                'defaultDurationMinutes' => $service->default_duration_minutes,
                'defaultChargeMinor' => $service->default_charge_minor,
                'currency' => $service->currency,
            ])
            ->values();

        return Envelope::success(data: $services, request: $request);
    }

    public function store(StoreServiceRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $service = Service::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility->getKey(),
            'department_id' => $request->validated('departmentId'),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'service_type' => $request->validated('serviceType'),
            'status' => $request->validated('status', 'active'),
            'default_duration_minutes' => $request->validated('defaultDurationMinutes'),
            'default_charge_minor' => $request->validated('defaultChargeMinor'),
            'currency' => $request->validated('currency'),
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'service.created',
            'service',
            $service->getKey(),
            [
                'code' => $service->code,
                'name' => $service->name,
                'serviceType' => $service->service_type,
                'facilityId' => $service->facility_id,
                'defaultChargeMinor' => $service->default_charge_minor,
                'currency' => $service->currency,
            ],
            $request,
        );

        return Envelope::success(
            data: self::present($service),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/services/'.$service->getKey(),
            ],
        );
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        AccessCheck::scoped($service, write: false);

        return Envelope::success(data: self::present($service), request: $request);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        AccessCheck::scoped($service, write: true);

        $changes = [];
        $fields = [
            'name' => 'name',
            'code' => 'code',
            'serviceType' => 'service_type',
            'status' => 'status',
            'defaultDurationMinutes' => 'default_duration_minutes',
            'defaultChargeMinor' => 'default_charge_minor',
            'currency' => 'currency',
        ];

        foreach ($fields as $input => $field) {
            if ($request->has($input)) {
                $changes[$field] = [$service->getAttribute($field), $request->validated($input)];
                $service->setAttribute($field, $request->validated($input));
            }
        }

        if ($request->has('departmentId')) {
            $changes['departmentId'] = [$service->department_id, $request->validated('departmentId')];
            $service->department_id = $request->validated('departmentId');
        }

        $service->updated_by = TenantContext::current()->user?->getKey();
        $service->save();

        $this->audit->record(
            'service.updated',
            'service',
            $service->getKey(),
            ['changes' => $changes],
            $request,
        );

        return Envelope::success(data: self::present($service), request: $request);
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        AccessCheck::scoped($service, write: true);

        $service->delete();

        $this->audit->record(
            'service.deleted',
            'service',
            $service->getKey(),
            ['code' => $service->code, 'name' => $service->name],
            $request,
        );

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Service $service): array
    {
        return [
            'id' => $service->getKey(),
            'facilityId' => $service->facility_id,
            'departmentId' => $service->department_id,
            'name' => $service->name,
            'code' => $service->code,
            'serviceType' => $service->service_type,
            'status' => $service->status,
            'defaultDurationMinutes' => $service->default_duration_minutes,
            'defaultChargeMinor' => $service->default_charge_minor,
            'currency' => $service->currency,
        ];
    }
}
