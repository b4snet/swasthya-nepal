<?php

namespace App\Http\Requests\Er;

use App\Http\Requests\ApiRequest;
use App\Models\ErEvent;
use Illuminate\Validation\Rule;

/**
 * POST er/encounters/{encounter}/events — append a time-stamped ER event
 * (medico-legal log). Notes are clinical context stored on the event —
 * never in audit payloads.
 */
class StoreErEventRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'eventType' => ['required', 'string', Rule::in([
                ErEvent::TYPE_ARRIVED,
                ErEvent::TYPE_REGISTERED,
                ErEvent::TYPE_TRIAGED,
                ErEvent::TYPE_REASSESSED,
                ErEvent::TYPE_SEEN_BY_DOCTOR,
                ErEvent::TYPE_TREATMENT_STARTED,
                ErEvent::TYPE_LAB_ORDERED,
                ErEvent::TYPE_MEDICATION_ADMINISTERED,
                ErEvent::TYPE_PROCEDURE,
                ErEvent::TYPE_OBSERVATION_STARTED,
                ErEvent::TYPE_DISPOSITION,
                ErEvent::TYPE_TRANSFERRED_OUT,
                ErEvent::TYPE_DISCHARGED,
                ErEvent::TYPE_OTHER,
            ])],
            'notes' => ['sometimes', 'string', 'max:2000'],
            'occurredAt' => ['sometimes', 'date', 'date_format:Y-m-d\TH:i:sP'],
        ];
    }
}
