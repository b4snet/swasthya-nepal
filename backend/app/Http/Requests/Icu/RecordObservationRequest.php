<?php

namespace App\Http\Requests\Icu;

use App\Http\Requests\ApiRequest;

/**
 * POST icu-admissions/{admission}/observations — record a high-frequency
 * observation set; the warning score is computed and escalation alerts are
 * opened (PRODUCT_REQUIREMENTS §6.11).
 */
class RecordObservationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'values' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'observedAt' => ['nullable', 'date'],
        ];
    }
}
