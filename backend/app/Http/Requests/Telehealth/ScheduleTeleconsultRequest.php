<?php

namespace App\Http\Requests\Telehealth;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/telehealth/schedule — schedule a teleconsult from an
 * existing teleconsult appointment (PRODUCT_REQUIREMENTS §6.20.1). The
 * appointment is validated by the controller (type + status + scope); the
 * request only carries an optional note.
 */
class ScheduleTeleconsultRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'appointmentId' => ['required', 'uuid'],
        ];
    }
}
