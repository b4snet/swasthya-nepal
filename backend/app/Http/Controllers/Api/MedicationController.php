<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Medication\StoreMedicationRequest;
use App\Models\Medication;
use App\Models\Organization;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The formulary (DATABASE.md §3.22): the tenant's medicine catalog — the
 * reference for prescribing. Prices are integer minor units, never floats.
 */
final class MedicationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Medication::query()
            ->where('tenant_id', $organization->getKey())
            ->orderBy('generic_name');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $medications = $query->get()
            ->map(fn (Medication $medication): array => self::present($medication))
            ->values();

        return Envelope::success(data: $medications, request: $request);
    }

    public function store(StoreMedicationRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $medication = Medication::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility->getKey(),
            'code' => $request->validated('code'),
            'generic_name' => $request->validated('genericName'),
            'brand_name' => $request->validated('brandName'),
            'strength' => $request->validated('strength'),
            'form' => $request->validated('form', 'tablet'),
            'unit' => $request->validated('unit'),
            'price_minor' => $request->validated('priceMinor'),
            'currency' => $request->validated('currency', 'NPR'),
            'is_controlled' => $request->validated('isControlled', false),
            'status' => Medication::STATUS_ACTIVE,
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'medication.created',
            'medication',
            $medication->getKey(),
            ['code' => $medication->code, 'genericName' => $medication->generic_name, 'priceMinor' => $medication->price_minor, 'facilityId' => $medication->facility_id],
            $request,
        );

        return Envelope::success(data: self::present($medication), status: 201, request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Medication $medication): array
    {
        return [
            'id' => $medication->getKey(),
            'facilityId' => $medication->facility_id,
            'code' => $medication->code,
            'genericName' => $medication->generic_name,
            'brandName' => $medication->brand_name,
            'strength' => $medication->strength,
            'form' => $medication->form,
            'unit' => $medication->unit,
            'priceMinor' => $medication->price_minor,
            'currency' => $medication->currency,
            'isControlled' => $medication->is_controlled,
            'status' => $medication->status,
        ];
    }
}
