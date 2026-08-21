<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\HospitalBranding;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class HospitalBrandingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Get the branding configuration for a facility.
     * Returns empty defaults if no branding record exists yet.
     */
    public function show(Request $request, Facility $facility): JsonResponse
    {
        AccessCheck::facility($facility->getKey(), write: false);

        $branding = HospitalBranding::query()
            ->where('tenant_id', $facility->tenant_id)
            ->where('facility_id', $facility->getKey())
            ->first();

        if ($branding === null) {
            return Envelope::success(data: ['branding' => null, 'defaults' => $this->defaults()], request: $request);
        }

        return Envelope::success(data: ['branding' => $branding->present()], request: $request);
    }

    /**
     * Create or update the branding configuration for a facility.
     * Upsert pattern: one branding record per facility.
     */
    public function update(Request $request, Facility $facility): JsonResponse
    {
        AccessCheck::facility($facility->getKey(), write: true);

        $validated = $request->validate([
            'hospitalName' => ['nullable', 'string', 'max:255'],
            'hospitalNameLocal' => ['nullable', 'string', 'max:255'],
            'logoUrl' => ['nullable', 'url', 'max:500'],
            'faviconUrl' => ['nullable', 'url', 'max:500'],
            'primaryColor' => ['nullable', 'string', 'max:20'],
            'secondaryColor' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'emergencyPhone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:500'],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'postalCode' => ['nullable', 'string', 'max:20'],
            'documentHeader' => ['nullable', 'string', 'max:1000'],
            'documentFooter' => ['nullable', 'string', 'max:1000'],
            'letterheadText' => ['nullable', 'string', 'max:2000'],
            'dateFormat' => ['nullable', 'string', 'max:30'],
            'timeFormat' => ['nullable', 'string', 'max:30'],
            'currency' => ['nullable', 'string', 'max:10'],
            'currencySymbol' => ['nullable', 'string', 'max:10'],
            'vatRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vatNumber' => ['nullable', 'string', 'max:50'],
            'registrationNumber' => ['nullable', 'string', 'max:100'],
            'panNumber' => ['nullable', 'string', 'max:50'],
            'termsAndConditions' => ['nullable', 'string', 'max:5000'],
            'privacyPolicy' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validator = Validator::make($validated, [], [])) {
            // Already validated above
        }

        $context = TenantContext::current();
        $branding = HospitalBranding::query()
            ->where('tenant_id', $facility->tenant_id)
            ->where('facility_id', $facility->getKey())
            ->first();

        $isCreate = $branding === null;
        $oldValues = $branding?->toArray();

        if ($isCreate) {
            $branding = HospitalBranding::query()->create(array_merge([
                'tenant_id' => $facility->tenant_id,
                'facility_id' => $facility->getKey(),
                'version' => 1,
                'updated_by' => $context->user?->getKey(),
            ], $this->mapToSnake($validated)));
        } else {
            $branding->fill($this->mapToSnake($validated));
            $branding->version += 1;
            $branding->updated_by = $context->user?->getKey();
            $branding->save();
        }

        $this->audit->record(
            $isCreate ? 'hospital_branding.created' : 'hospital_branding.updated',
            'hospital_brandings',
            $branding->getKey(),
            ['changes' => $this->mapToSnake($validated), 'old' => $oldValues],
            $request,
        );

        return Envelope::success(data: ['branding' => $branding->present()], request: $request);
    }

    /**
     * Get the full branding for use in document generation (PDF, print, etc.).
     * This endpoint is used by the document rendering pipeline.
     */
    public function forDocument(Request $request, Facility $facility): JsonResponse
    {
        AccessCheck::facility($facility->getKey(), write: false);

        $branding = HospitalBranding::query()
            ->where('tenant_id', $facility->tenant_id)
            ->where('facility_id', $facility->getKey())
            ->first();

        $data = $branding !== null ? array_merge($branding->present(), $this->defaults()) : $this->defaults();

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * Map camelCase request keys to snake_case database columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapToSnake(array $data): array
    {
        $mapping = [
            'hospitalName' => 'hospital_name',
            'hospitalNameLocal' => 'hospital_name_local',
            'logoUrl' => 'logo_url',
            'faviconUrl' => 'favicon_url',
            'primaryColor' => 'primary_color',
            'secondaryColor' => 'secondary_color',
            'emergencyPhone' => 'emergency_phone',
            'addressLine1' => 'address_line1',
            'addressLine2' => 'address_line2',
            'postalCode' => 'postal_code',
            'documentHeader' => 'document_header',
            'documentFooter' => 'document_footer',
            'letterheadText' => 'letterhead_text',
            'dateFormat' => 'date_format',
            'timeFormat' => 'time_format',
            'currencySymbol' => 'currency_symbol',
            'vatRate' => 'vat_rate',
            'vatNumber' => 'vat_number',
            'registrationNumber' => 'registration_number',
            'panNumber' => 'pan_number',
            'termsAndConditions' => 'terms_and_conditions',
            'privacyPolicy' => 'privacy_policy',
        ];

        $result = [];
        foreach ($data as $key => $value) {
            $result[$mapping[$key] ?? $key] = $value;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'dateFormat' => 'Y-m-d',
            'timeFormat' => 'H:i',
            'currency' => 'NPR',
            'currencySymbol' => 'Rs.',
            'vatRate' => 0,
            'country' => 'Nepal',
        ];
    }
}
