<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\ApiRequest;

/**
 * PATCH /api/v1/branches/{branch}.
 */
class UpdateBranchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => ['sometimes', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,49}$/'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
