<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class StoreImageReferenceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'references' => ['required', 'array', 'min:1', 'max:50'],
            'references.*.referenceType' => ['required', 'in:dicom_study_instance_uid,dicom_series_instance_uid,dicom_sop_instance_uid,pacs_url'],
            'references.*.referenceValue' => ['required', 'string', 'max:500'],
            'references.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'references' => 'references',
        ];
    }
}
