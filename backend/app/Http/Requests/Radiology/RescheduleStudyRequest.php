<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleStudyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'modalityId' => ['required', 'uuid'],
            'scheduledAt' => ['required', 'date'],
            'rescheduleReason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lockVersion' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'modalityId' => 'modality',
            'scheduledAt' => 'scheduled at',
            'rescheduleReason' => 'reschedule reason',
            'lockVersion' => 'lock version',
        ];
    }
}