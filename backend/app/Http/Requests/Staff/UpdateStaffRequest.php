<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\ApiRequest;
use App\Models\Department;
use App\Models\Staff;
use App\Support\TenantContext;

/**
 * PATCH /api/v1/staff/{staff}.
 *
 * Department moves must stay inside the same facility. License changes are
 * audited as a fact (never the value). Status transitions to 'departed' end
 * the employment record — history persists (DATABASE.md §3.10).
 */
class UpdateStaffRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staff = $this->route('staff');
        $context = TenantContext::current();

        return [
            'fullName' => ['sometimes', 'string', 'min:2', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'departmentId' => [
                'sometimes',
                'uuid',
                function (string $attribute, mixed $value, callable $fail) use ($staff, $context): void {
                    if ($staff === null) {
                        return;
                    }

                    $department = Department::query()->where('id', $value)->first();

                    if ($department === null) {
                        $fail('The department does not exist.');

                        return;
                    }

                    if ($department->tenant_id !== $context->tenantId() || $department->facility_id !== $staff->facility_id) {
                        $fail('The department must belong to the same facility as the staff member.');
                    }
                },
            ],
            'licenseNumber' => ['nullable', 'string', 'max:255'],
            'hireDate' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:active,on_leave,departed'],
        ];
    }
}
