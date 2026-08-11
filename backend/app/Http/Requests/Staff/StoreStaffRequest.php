<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasFacilityContext;
use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use App\Support\TenantContext;

/**
 * POST /api/v1/organizations/{organization}/staff.
 *
 * employee_code is unique per tenant (DATABASE.md §3.10); at most one
 * non-departed staff record per user per tenant (partial unique index).
 * The department must live in the same tenant and facility as the staff
 * member. The license number is encrypted at rest and never logged.
 */
class StoreStaffRequest extends ApiRequest
{
    use HasFacilityContext;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $context = TenantContext::current();

        return [
            'facilityId' => $this->facilityIdRules(),
            'employeeCode' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, callable $fail) use ($context): void {
                    $exists = Staff::query()
                        ->where('tenant_id', $context->tenantId())
                        ->whereRaw('lower(employee_code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A staff member with this employee code already exists in this organization.');
                    }
                },
            ],
            'fullName' => ['required', 'string', 'min:2', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'departmentId' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, callable $fail) use ($context): void {
                    $facilityId = $context->facilityId() ?? $this->input('facilityId');
                    $department = Department::query()->where('id', $value)->first();

                    if ($department === null) {
                        $fail('The department does not exist.');

                        return;
                    }

                    if ($department->tenant_id !== $context->tenantId() || ($facilityId !== null && $department->facility_id !== $facilityId)) {
                        $fail('The department must belong to the same facility as the staff member.');
                    }
                },
            ],
            'userId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail) use ($context): void {
                    if ($value === null) {
                        return;
                    }

                    $exists = Staff::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('user_id', $value)
                        ->where('status', '!=', Staff::STATUS_DEPARTED)
                        ->exists();

                    if ($exists) {
                        $fail('This user already has an active staff profile in this organization.');
                    }
                },
            ],
            'licenseNumber' => ['nullable', 'string', 'max:255'],
            'hireDate' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:active,on_leave'],
        ];
    }
}
