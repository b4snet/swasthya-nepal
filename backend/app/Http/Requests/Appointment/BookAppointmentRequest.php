<?php

namespace App\Http\Requests\Appointment;

use App\Http\Requests\ApiRequest;

/**
 * POST appointments — book a slot for a patient with a provider.
 *
 * The slot is validated against derived availability (SlotService) and the
 * partial unique index races as the final double-booking guard.
 */
class BookAppointmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'providerStaffId' => ['required', 'uuid'],
            'serviceId' => ['nullable', 'uuid'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'appointmentType' => ['nullable', 'in:opd,follow_up,procedure,teleconsult'],
            'source' => ['nullable', 'in:counter,portal,walk_in'],
        ];
    }
}
