<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/portal/login (PRODUCT_REQUIREMENTS §6.2, DATABASE.md §3.53).
 *
 * Validation only — credential verification, tenant disambiguation, and
 * lockout live in the service (an unknown organization code and a wrong
 * password must be indistinguishable — no account/tenant enumeration).
 */
class LoginRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organizationCode' => ['required', 'string', 'max:50'],
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ];
    }
}
