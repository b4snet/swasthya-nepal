<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class StoreModalityScheduleExceptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'modalityId' => ['required', 'uuid'],
            'exceptionDate' => ['required', 'date'],
            'startTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'endTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'isBlocked' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'modalityId' => 'modality',
            'exceptionDate' => 'exception date',
            'startTime' => 'start time',
            'endTime' => 'end time',
            'reason' => 'reason',
            'isBlocked' => 'is blocked',
        ];
    }
}