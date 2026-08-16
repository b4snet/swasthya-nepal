<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleStudyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'modalityId' => ['required', 'uuid'],
            'scheduledAt' => ['required', 'date'],
            'preparationInstructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
            'preparationInstructions' => 'preparation instructions',
            'lockVersion' => 'lock version',
        ];
    }
}
