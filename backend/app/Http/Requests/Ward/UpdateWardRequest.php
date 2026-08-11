<?php

namespace App\Http\Requests\Ward;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasBranchContext;
use App\Models\Ward;
use App\Support\TenantContext;

/**
 * PATCH /api/v1/wards/{ward}.
 */
class UpdateWardRequest extends ApiRequest
{
    use HasBranchContext;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ward = $this->route('ward');
        $context = TenantContext::current();

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($ward, $context): void {
                    if ($ward === null) {
                        return;
                    }

                    $exists = Ward::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $ward->facility_id)
                        ->where('id', '!=', $ward->getKey())
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A ward with this code already exists in this facility.');
                    }
                },
            ],
            'branchId' => $this->branchIdRules($ward?->facility_id),
            'wardType' => ['sometimes', 'in:general,surgery,pediatric,icu,maternity,other'],
            'settings' => ['sometimes', 'array'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
