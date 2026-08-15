<?php

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/**
 * POST encounters/{encounter}/follow-ups — plan a return visit or
 * teleconsult from the generating encounter. planned_at must be in the
 * future; the provider defaults to the encounter's provider.
 */
class StoreFollowUpRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'followUpType' => ['required', 'string', 'in:return_visit,teleconsult'],
            'plannedAt' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'providerStaffId' => ['nullable', 'uuid'],
        ];
    }
}
