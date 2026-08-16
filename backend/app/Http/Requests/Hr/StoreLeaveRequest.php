<?php

namespace App\Http\Requests\Hr;

use App\Http\Requests\ApiRequest;

/**
 * POST leave-requests — a staff member requests leave (type, range).
 * Balance is checked at APPROVAL time, not at request time (a request may
 * legitimately be pending while earlier leave is being adjusted).
 */
class StoreLeaveRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staffId' => ['required', 'string', 'uuid'],
            'leaveTypeId' => ['required', 'string', 'uuid'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['required', 'date', 'after_or_equal:startsOn'],
            'daysRequested' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
