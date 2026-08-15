<?php

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/**
 * POST follow-ups/{followUp}/cancel — cancellation with a captured reason
 * (the plan is clinical history, never deleted).
 */
class CancelFollowUpRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
