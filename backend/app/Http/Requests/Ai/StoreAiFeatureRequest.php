<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/ai/features — register an AI function in the governance
 * registry (AI_RULES.md §19). The entry is created with the kill switch
 * OFF and must be activated (evaluation evidence + approved model) and then
 * enabled before it can ever run.
 */
class StoreAiFeatureRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'function' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
            'tier' => ['required', 'integer', 'between:1,4'],
            'ownerStaffId' => ['nullable', 'uuid'],
            'modelId' => ['required', 'string', 'max:100'],
            'modelVersion' => ['required', 'string', 'max:100'],
            'purpose' => ['required', 'string', 'max:2000'],
            'nonGoals' => ['nullable', 'string', 'max:2000'],
            'minInputs' => ['nullable', 'array'],
            'outputSchema' => ['nullable', 'array'],
            'confidenceThreshold' => ['nullable', 'numeric', 'between:0,1'],
            'fallbackMode' => ['nullable', 'string', 'max:500'],
            'evaluationRef' => ['nullable', 'string', 'max:500'],
            'reviewCadence' => ['nullable', 'string', 'max:100'],
            'auditClass' => ['nullable', 'string', 'max:60'],
        ];
    }
}
