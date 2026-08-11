<?php

namespace App\Http\Requests\Schedule;

use App\Http\Requests\ApiRequest;

/**
 * POST schedule exceptions — leave / holiday / blocked date for a provider.
 */
class StoreScheduleExceptionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staffId' => ['required', 'uuid'],
            'exceptionDate' => ['required', 'date'],
            'reason' => ['required', 'in:leave,holiday,block'],
        ];
    }
}
