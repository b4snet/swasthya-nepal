<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/ai/features/{aiFeature}/invoke — invoke a gated AI
 * function. The context may carry ONLY the minimum input fields the
 * feature's registry entry permits (privilege boundary, AI_RULES.md
 * §13–14); the service strips everything else.
 */
class InvokeAiFeatureRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'context' => ['required', 'array'],
            'context.*' => ['nullable', 'string'],
        ];
    }
}
