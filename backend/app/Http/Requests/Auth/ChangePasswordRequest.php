<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * POST /auth/password/change — an AUTHENTICATED user changes their own
 * password. Requires the current password (proof of account control) plus a
 * new password meeting the same strength floor as initial provisioning and
 * reset (min:12, ResetPasswordRequest / StoreUserRequest) and a
 * confirmation. SECURITY.md §1–2.
 */
class ChangePasswordRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
            // Declared so the strict unknown-field screen accepts it; the
            // `confirmed` rule on new_password requires it to match.
            'new_password_confirmation' => ['required', 'string', 'max:255'],
        ];
    }
}
