<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class CompleteAcquisitionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lockVersion' => ['required', 'integer', 'min:0'],
            'performedByStaffId' => ['required', 'uuid'],
            'acquisitionNotes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'lockVersion' => 'lock version',
            'performedByStaffId' => 'performed by staff',
            'acquisitionNotes' => 'acquisition notes',
        ];
    }
}