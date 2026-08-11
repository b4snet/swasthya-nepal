<?php

namespace App\Http\Requests\Appointment;

use App\Http\Requests\ApiRequest;

/**
 * POST appointments/{appointment}/cancel — cancellation always carries a
 * reason (PRODUCT_REQUIREMENTS §6.3, DATABASE.md §3.15).
 */
class CancelAppointmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
