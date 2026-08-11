<?php

namespace App\Http\Requests\Facility;

use App\Http\Requests\ApiRequest;
use App\Models\Facility;

/**
 * POST /api/v1/organizations/{organization}/facilities.
 *
 * Facility codes are unique per tenant among live facilities (DATABASE.md
 * §3.2) — checked case-insensitively against the target organization.
 */
class StoreFacilityRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = $this->route('organization')?->getKey();

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => [
                'required',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($tenantId): void {
                    $exists = Facility::query()
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A facility with this code already exists in this organization.');
                    }
                },
            ],
            'timezone' => ['required', 'timezone'],
        ];
    }
}
