<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class StartAcquisitionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lockVersion' => ['required', 'integer', 'min:0'],
            'procedureStartedAt' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'lockVersion' => 'lock version',
            'procedureStartedAt' => 'procedure started at',
        ];
    }
}