<?php

namespace App\Http\Requests\Location;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasBranchContext;
use App\Models\Location;
use App\Support\TenantContext;

/**
 * PATCH /api/v1/locations/{location}.
 */
class UpdateLocationRequest extends ApiRequest
{
    use HasBranchContext;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $location = $this->route('location');
        $context = TenantContext::current();

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($location, $context): void {
                    if ($location === null) {
                        return;
                    }

                    $exists = Location::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $location->facility_id)
                        ->where('id', '!=', $location->getKey())
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A location with this code already exists in this facility.');
                    }
                },
            ],
            'branchId' => $this->branchIdRules($location?->facility_id),
            'type' => ['sometimes', 'in:store,waiting_area,nursing_station,procedure_area,other'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
