<?php

namespace App\Http\Requests\Telehealth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/telehealth/teleconsults/{teleconsult}/start — begin the
 * consultation (ready → in_progress) after the consent gate passes. The
 * medium is video (default) or phone (the documented connectivity
 * fallback); the ACTIVE telehealth consent must cover it.
 */
class StartTeleconsultRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'medium' => ['sometimes', 'in:video,phone'],
        ];
    }
}
