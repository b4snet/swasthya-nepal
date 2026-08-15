<?php

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/**
 * POST follow-ups/{followUp}/book — link the plan to the actual booked
 * appointment. The appointment must belong to the same patient in the same
 * facility; that ownership is verified in the controller.
 */
class BookFollowUpRequest extends ApiRequest
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
