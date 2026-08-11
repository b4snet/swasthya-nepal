<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/organizations — tenant provisioning (TENANCY.md §12).
 *
 * The code is the human-facing tenant slug (TENANCY.md §1): lowercase
 * letters, digits, hyphens; unique and immutable in practice.
 */
class StoreOrganizationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'code' => [
                'required',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{2,49}$/',
                Rule::unique('organizations', 'code'),
            ],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'timezone' => ['required', 'timezone'],
        ];
    }
}
