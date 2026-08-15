<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * Shared shape for the TOTP code-bearing endpoints (activate,
 * recovery-code regeneration): both require a `code`.
 */
class MfaCodeRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
