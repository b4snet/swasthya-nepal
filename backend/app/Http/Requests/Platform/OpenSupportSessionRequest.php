<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/platform/support-sessions (TENANCY.md V2 §8).
 *
 * A support session is explicit by construction: target organization,
 * mandatory reason (min 12 chars — a real justification), and a hard expiry
 * capped at 24 hours. No reason, no session.
 */
class OpenSupportSessionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organizationId' => ['required', 'uuid'],
            'facilityId' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:12', 'max:1000'],
            'expiresInMinutes' => ['required', 'integer', 'min:15', 'max:1440'],
        ];
    }
}
