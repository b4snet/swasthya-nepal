<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\ApiRequest;
use App\Models\Branch;
use App\Support\TenantContext;

/**
 * POST /api/v1/facilities/{facility}/branches.
 *
 * Codes are unique per (tenant, facility) among live branches (DATABASE.md
 * §3.x) — checked case-insensitively against the facility in the URL.
 */
class StoreBranchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $facility = $this->route('facility');

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => [
                'required',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($facility): void {
                    $exists = Branch::query()
                        ->where('tenant_id', $facility?->tenant_id)
                        ->where('facility_id', $facility?->getKey())
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A branch with this code already exists in this facility.');
                    }
                },
            ],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    /**
     * The store route binds the facility; no client-supplied facility id is
     * accepted (TENANCY.md §7).
     */
    public function authorize(): bool
    {
        $facility = $this->route('facility');

        return $facility !== null && $facility->tenant_id === TenantContext::current()->tenantId();
    }
}
