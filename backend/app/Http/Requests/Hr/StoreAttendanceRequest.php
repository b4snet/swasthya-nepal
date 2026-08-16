<?php

namespace App\Http\Requests\Hr;

use App\Http\Requests\ApiRequest;

/**
 * POST attendance — record a staff member's clock-in/out (or schedule-based
 * attendance) for a day.
 */
class StoreAttendanceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staffId' => ['required', 'string', 'uuid'],
            'attendanceDate' => ['required', 'date'],
            'clockInAt' => ['nullable', 'date'],
            'clockOutAt' => ['nullable', 'date', 'after:clockInAt'],
            'status' => ['sometimes', 'in:present,absent,late,leave'],
            'source' => ['sometimes', 'in:clock,schedule,manual'],
        ];
    }
}
