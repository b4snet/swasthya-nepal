<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/platform/users/{user}/assignments.
 */
class GrantPlatformRoleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roleCode' => ['required', 'string', 'exists:roles,code'],
        ];
    }
}
