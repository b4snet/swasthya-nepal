<?php

namespace App\Http\Requests\Ot;

use App\Http\Requests\ApiRequest;

/**
 * POST procedures/{procedure}/events — record a time-stamped
 * intra-operative event (PRODUCT_REQUIREMENTS §6.10).
 */
class RecordSurgicalEventRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'eventType' => ['required', 'in:time_out,incision,closure,sign_out,complication,other'],
            'occurredAt' => ['nullable', 'date'],
            'staffId' => ['nullable', 'string', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
