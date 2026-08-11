<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/consents/{consent}/revoke — revocation is a state change
 * with a required reason (DATABASE.md §3.39); it is audited end-to-end.
 */
class RevokeConsentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
