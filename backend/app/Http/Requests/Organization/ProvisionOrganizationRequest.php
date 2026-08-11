<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\ApiRequest;
use App\Models\User;

/**
 * POST /api/v1/platform/organizations/{organization}/provision (TENANCY.md
 * V2 §8, §12). Strong initial password required — no default credentials
 * (MASTER_RULES.md §7.7); email uniqueness is case-insensitive.
 */
class ProvisionOrganizationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facilityName' => ['required', 'string', 'min:2', 'max:255'],
            'facilityCode' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,49}$/'],
            'adminEmail' => [
                'required',
                'email',
                function (string $attribute, mixed $value, callable $fail): void {
                    $exists = User::query()
                        ->whereRaw('lower(email) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A user with this email is already registered.');
                    }
                },
            ],
            'adminPassword' => ['required', 'string', 'min:12'],
        ];
    }
}
