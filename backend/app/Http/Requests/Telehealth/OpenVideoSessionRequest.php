<?php

namespace App\Http\Requests\Telehealth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/telehealth/teleconsults/{teleconsult}/video-sessions —
 * open a secure video session (metadata only). `recordingRequested` is the
 * EXPLICIT operator decision at session open; the actual recording start is
 * the separate, permission+consent+policy-gated call.
 */
class OpenVideoSessionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'participantType' => ['sometimes', 'in:provider,patient'],
            'recordingRequested' => ['sometimes', 'boolean'],
            'providerSessionRef' => ['nullable', 'string', 'max:255'],
        ];
    }
}
