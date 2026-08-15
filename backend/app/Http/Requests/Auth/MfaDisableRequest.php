<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/auth/mfa/disable — removes MFA. Requires the current password
 * (step-up) AND a valid TOTP code: an MFA-protected account cannot be
 * downgraded with a recovery code or a stolen session alone (SECURITY.md §3:
 * removal is gated; admin-assisted reset is a recorded future flow).
 */
class MfaDisableRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
