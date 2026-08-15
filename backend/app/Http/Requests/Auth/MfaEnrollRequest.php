<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/auth/mfa/enroll — starts MFA enrollment. Requires the current
 * password (step-up): the caller must prove account control before a new
 * authenticator secret is issued.
 */
class MfaEnrollRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
