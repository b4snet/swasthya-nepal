<?php

namespace App\Http\Requests\Hr;

use App\Http\Requests\ApiRequest;

/**
 * POST shift-templates — create a shift definition (day/night/rotating).
 */
class StoreShiftTemplateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'departmentId' => ['nullable', 'string', 'uuid'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'shiftType' => ['required', 'in:day,night,rotating'],
            'startsAt' => ['required', 'date_format:H:i'],
            'endsAt' => ['required', 'date_format:H:i'],
            'workingMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
