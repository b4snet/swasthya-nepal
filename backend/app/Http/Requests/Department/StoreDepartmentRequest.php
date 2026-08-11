<?php

namespace App\Http\Requests\Department;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasBranchContext;
use App\Http\Requests\Concerns\HasFacilityContext;
use App\Models\Department;
use App\Support\TenantContext;

/**
 * POST /api/v1/organizations/{organization}/departments.
 *
 * Codes are unique per (tenant, facility) among live departments (DATABASE.md
 * §3.8). The parent department must live in the same tenant and facility —
 * the composite self-FK makes a mismatch structurally impossible; this
 * pre-check returns a clean 422 instead of a constraint violation.
 */
class StoreDepartmentRequest extends ApiRequest
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

                    $exists = Department::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $facilityId)
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A department with this code already exists in this facility.');
                    }
                },
            ],
            'facilityId' => $this->facilityIdRules(),
            'branchId' => $this->branchIdRules(),
            'parentDepartmentId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail) use ($context): void {
                    if ($value === null) {
                        return;
                    }

                    $facilityId = $context->facilityId() ?? $this->input('facilityId');
                    $parent = Department::query()->where('id', $value)->first();

                    if ($parent === null) {
                        $fail('The parent department does not exist.');

                        return;
                    }

                    if ($parent->tenant_id !== $context->tenantId() || ($facilityId !== null && $parent->facility_id !== $facilityId)) {
                        $fail('The parent department must belong to the same facility.');
                    }
                },
            ],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
