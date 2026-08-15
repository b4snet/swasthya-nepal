<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/auth/mfa/challenge — completes a login MFA challenge with a
 * TOTP code or a single-use recovery code.
 */
class MfaChallengeRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challengeId' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
