<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class AmendRadiologyReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:20000'],
            'impression' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'criticalFindings' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'content' => 'report content',
            'impression' => 'impression',
            'criticalFindings' => 'critical findings',
        ];
    }
}
