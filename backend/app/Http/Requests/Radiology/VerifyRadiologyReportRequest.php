<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class VerifyRadiologyReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lockVersion' => ['required', 'integer', 'min:0'],
        ];
    }
}
