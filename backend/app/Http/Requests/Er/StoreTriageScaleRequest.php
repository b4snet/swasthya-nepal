<?php

namespace App\Http\Requests\Er;

use App\Http\Requests\ApiRequest;

/**
 * POST organizations/{organization}/er/triage-scales — add a level to the
 * configurable acuity catalog (PRODUCT_REQUIREMENTS §6.6). `level` is the
 * priority ordinal (1 = most urgent); `reassessmentMinutes` drives the
 * reassessment schedule.
 */
class StoreTriageScaleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facilityId' => ['sometimes', 'uuid'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', 'integer', 'min:1', 'max:10'],
            'color' => ['sometimes', 'string', 'max:20'],
            'reassessmentMinutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'isDefault' => ['sometimes', 'boolean'],
        ];
    }
}
