<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/auth/password/forgot — request a password-reset token.
 * Email only; the response is deliberately generic to avoid account
 * enumeration (SECURITY.md §5).
 */
class ForgotPasswordRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
        ];
    }
}
