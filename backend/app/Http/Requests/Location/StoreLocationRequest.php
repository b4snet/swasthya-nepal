<?php

namespace App\Http\Requests\Location;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasBranchContext;
use App\Http\Requests\Concerns\HasFacilityContext;
use App\Models\Location;
use App\Support\TenantContext;

/**
 * POST /api/v1/organizations/{organization}/locations.
 */
class StoreLocationRequest extends ApiRequest
{
    use HasBranchContext, HasFacilityContext;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $context = TenantContext::current();

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => [
                'required',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($context): void {
                    $facilityId = $context->facilityId() ?? $this->input('facilityId');

                    if ($facilityId === null) {
                        return;
                    }

                    $exists = Location::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $facilityId)
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A location with this code already exists in this facility.');
                    }
                },
            ],
            'facilityId' => $this->facilityIdRules(),
            'branchId' => $this->branchIdRules(),
            'type' => ['required', 'in:store,waiting_area,nursing_station,procedure_area,other'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
