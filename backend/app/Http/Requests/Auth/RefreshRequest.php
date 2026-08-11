<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/auth/refresh.
 *
 * The refresh token may travel in the request body (mobile/API clients) or
 * in the httpOnly swasthya_refresh cookie (SPA) — both are accepted; at
 * least one must be present (validated in the controller, which resolves
 * the cookie).
 */
class RefreshRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'refreshToken' => ['nullable', 'string'],
        ];
    }
}
