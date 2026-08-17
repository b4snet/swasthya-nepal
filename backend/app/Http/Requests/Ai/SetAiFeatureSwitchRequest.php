<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

/**
 * PATCH /api/v1/ai/features/{aiFeature}/switch — toggle the per-feature
 * KILL SWITCH. Disabling is always allowed and audited (MASTER_RULES.md
 * §38); enabling never auto-runs anything (activation is a separate act).
 */
class SetAiFeatureSwitchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
