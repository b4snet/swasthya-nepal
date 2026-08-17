<?php

namespace App\Http\Requests\Cdss;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/cdss/pathways/{cdssRule}/evaluate — evaluate a registered
 * pathway rule against a patient context. Advisory only: the output is a
 * suggestion for the clinician to accept or ignore — nothing is applied.
 */
class EvaluatePathwayRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'context' => ['required', 'array'],
            'context.*' => ['nullable', 'string'],
        ];
    }
}
