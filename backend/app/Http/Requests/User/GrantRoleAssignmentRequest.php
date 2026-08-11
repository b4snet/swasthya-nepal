<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/organizations/{organization}/users/{user}/assignments.
 *
 * Role-scope consistency (org roles have no facility; facility roles require
 * one) is enforced in the controller, after the role is resolved — the
 * validation layer only checks shapes.
 */
class GrantRoleAssignmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roleCode' => ['required', 'string', 'exists:roles,code'],
            'facilityId' => ['nullable', 'uuid'],
        ];
    }
}
