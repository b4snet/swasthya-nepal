<?php

namespace App\Http\Requests\Service;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasFacilityContext;
use App\Models\Department;
use App\Models\Service;
use App\Support\TenantContext;

/**
 * POST /api/v1/organizations/{organization}/services.
 *
 * The facility's service catalog (schedules reference service_id, DATABASE.md
 * §3.16). Rates are integer minor units, never floats (DATABASE.md §0.4).
 */
class StoreServiceRequest extends ApiRequest
{
    use HasFacilityContext;

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

                    $exists = Service::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $facilityId)
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A service with this code already exists in this facility.');
                    }
                },
            ],
            'facilityId' => $this->facilityIdRules(),
            'serviceType' => ['required', 'in:opd_consultation,procedure,investigation,follow_up,other'],
            'departmentId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail) use ($context): void {
                    if ($value === null) {
                        return;
                    }

                    $facilityId = $context->facilityId() ?? $this->input('facilityId');
                    $department = Department::query()->where('id', $value)->first();

                    if ($department === null) {
                        $fail('The department does not exist.');

                        return;
                    }

                    if ($department->tenant_id !== $context->tenantId() || ($facilityId !== null && $department->facility_id !== $facilityId)) {
                        $fail('The department must belong to the same facility as the service.');
                    }
                },
            ],
            'defaultDurationMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'defaultChargeMinor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
