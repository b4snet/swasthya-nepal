<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiRequest;
use App\Models\User;

/**
 * POST /api/v1/organizations/{organization}/users.
 *
 * Password floor per SECURITY.md §2 (server-enforced policy): a strong
 * initial password is required at creation — no default credentials exist
 * (MASTER_RULES.md §7.7). Email uniqueness is case-insensitive (DATABASE.md
 * §3.4).
 */
class StoreUserRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
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
            'password' => ['required', 'string', 'min:12'],
            'roleCode' => ['required', 'string', 'exists:roles,code'],
            'facilityId' => ['nullable', 'uuid'],
        ];
    }
}
