<?php

namespace App\Http\Requests\Ot;

use App\Http\Requests\ApiRequest;

/**
 * POST procedure-requests/{request}/schedule — assign a theatre/date/time
 * with conflict detection (PRODUCT_REQUIREMENTS §6.10).
 */
class ScheduleProcedureRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'theatreId' => ['required', 'string', 'uuid'],
            'scheduledAt' => ['required', 'date'],
            'durationMinutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ];
    }
}
