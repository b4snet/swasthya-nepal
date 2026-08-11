<?php

namespace App\Http\Requests\Service;

use App\Http\Requests\ApiRequest;
use App\Models\Department;
use App\Models\Service;
use App\Support\TenantContext;

/**
 * PATCH /api/v1/services/{service}.
 */
class UpdateServiceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = $this->route('service');
        $context = TenantContext::current();

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($service, $context): void {
                    if ($service === null) {
                        return;
                    }

                    $exists = Service::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $service->facility_id)
                        ->where('id', '!=', $service->getKey())
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A service with this code already exists in this facility.');
                    }
                },
            ],
            'serviceType' => ['sometimes', 'in:opd_consultation,procedure,investigation,follow_up,other'],
            'departmentId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail) use ($service, $context): void {
                    if ($value === null || $service === null) {
                        return;
                    }

                    $department = Department::query()->where('id', $value)->first();

                    if ($department === null) {
                        $fail('The department does not exist.');

                        return;
                    }

                    if ($department->tenant_id !== $context->tenantId() || $department->facility_id !== $service->facility_id) {
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
