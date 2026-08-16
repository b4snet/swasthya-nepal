<?php

namespace App\Http\Requests\Telehealth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/telehealth/video-sessions/{videoSession}/fail — a
 * connectivity failure ends the session and records the teleconsult's
 * documented fallback (phone / in_person / reschedule). The reason is a
 * clinical fact and stays on the clinical row — never in logs.
 */
class FailVideoSessionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fallbackMode' => ['required', 'in:phone,in_person,reschedule'],
            'fallbackReason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
