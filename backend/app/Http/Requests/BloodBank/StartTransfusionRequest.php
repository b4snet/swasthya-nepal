<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST transfusions — start a transfusion of an issued unit with positive
 * identification; a SECOND staff member must verify (dual verification —
 * PRODUCT_REQUIREMENTS §6.12).
 */
class StartTransfusionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bloodUnitId' => ['required', 'string', 'uuid'],
            'patientId' => ['required', 'string', 'uuid'],
            'crossmatchId' => ['required', 'string', 'uuid'],
            'encounterId' => ['nullable', 'string', 'uuid'],
            'startedAt' => ['nullable', 'date'],
        ];
    }
}
