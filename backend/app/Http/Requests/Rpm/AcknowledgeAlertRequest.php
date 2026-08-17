<?php

namespace App\Http\Requests\Rpm;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/rpm/alerts/{alert}/acknowledge — the human-mediated
 * acknowledgment: WHO acknowledged, WHEN, and WHY (a required note). CAS
 * open → acknowledged. The note is clinical context and is recorded on the
 * alert row, never in the audit payload.
 */
class AcknowledgeAlertRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
