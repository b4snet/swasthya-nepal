<?php

namespace App\Http\Requests\Department;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasBranchContext;
use App\Models\Department;
use App\Support\TenantContext;

/**
 * PATCH /api/v1/departments/{department}.
 */
class UpdateDepartmentRequest extends ApiRequest
{
    use HasBranchContext;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->route('department');
        $context = TenantContext::current();

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($department, $context): void {
                    if ($department === null) {
                        return;
                    }

                    $exists = Department::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $department->facility_id)
                        ->where('id', '!=', $department->getKey())
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A department with this code already exists in this facility.');
                    }
                },
            ],
            'branchId' => $this->branchIdRules($department?->facility_id),
            'parentDepartmentId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail) use ($department, $context): void {
                    if ($value === null || $department === null) {
                        return;
                    }

                    $parent = Department::query()->where('id', $value)->first();

                    if ($parent === null || $parent->tenant_id !== $context->tenantId() || $parent->facility_id !== $department->facility_id) {
                        $fail('The parent department must belong to the same facility.');
                    }
                },
            ],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
