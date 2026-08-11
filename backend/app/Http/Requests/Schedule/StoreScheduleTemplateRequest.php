<?php

namespace App\Http\Requests\Schedule;

use App\Http\Requests\ApiRequest;

/**
 * POST schedule templates — recurring weekly provider availability.
 */
class StoreScheduleTemplateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staffId' => ['required', 'uuid'],
            'serviceId' => ['nullable', 'uuid'],
            'dayOfWeek' => ['required', 'integer', 'between:0,6'],
            'startsAt' => ['required', 'date_format:H:i'],
            'endsAt' => ['required', 'date_format:H:i', 'after:startsAt'],
            'slotMinutes' => ['required', 'integer', 'between:5,240'],
            'capacity' => ['required', 'integer', 'between:1,99'],
            'validFrom' => ['required', 'date'],
            'validTo' => ['nullable', 'date', 'after_or_equal:validFrom'],
        ];
    }
}
