<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/auth/password/reset — complete a password reset with the
 * emailed token and a new password. The token is single-use and expires;
 * the password must meet the same strength rule as initial provisioning
 * (min:12, StoreUserRequest).
 */
class ResetPasswordRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ];
    }
}
