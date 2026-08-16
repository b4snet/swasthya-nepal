<?php

namespace App\Http\Requests\Telehealth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/telehealth/video-sessions/{videoSession}/recording — start
 * an EXPLICIT recording (telehealth:record gate + facility policy +
 * patient consent) or stop a running one. The storage ref is a reference
 * (object-store key), never media content.
 */
class RecordingRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:start,stop'],
            'storageRef' => ['required_if:action,start', 'nullable', 'string', 'max:255'],
        ];
    }
}
