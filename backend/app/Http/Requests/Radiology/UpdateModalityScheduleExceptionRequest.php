<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class UpdateModalityScheduleExceptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'exceptionDate' => ['sometimes', 'date'],
            'startTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'endTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'isBlocked' => ['sometimes', 'boolean'],
            'lockVersion' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'exceptionDate' => 'exception date',
            'startTime' => 'start time',
            'endTime' => 'end time',
            'reason' => 'reason',
            'isBlocked' => 'is blocked',
            'lockVersion' => 'lock version',
        ];
    }
}