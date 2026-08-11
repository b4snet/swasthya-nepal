<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/auth/login (API_CONTRACTS.md §21.1).
 *
 * Validation only — credential verification and lockout live in the
 * controller (an invalid password must not be distinguishable from an
 * unknown account at the validation layer).
 */
class LoginRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
