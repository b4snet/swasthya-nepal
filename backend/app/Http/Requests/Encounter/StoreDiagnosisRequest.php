<?php

namespace App\Http\Requests\Encounter;

use App\Http\Requests\ApiRequest;

/**
 * POST encounters/{encounter}/diagnoses — a diagnosis is a clinical fact
 * (DATABASE.md §3.18); code optional but validated when given.
 */
class StoreDiagnosisRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20'],
            'codingSystem' => ['nullable', 'in:icd10,snomed,custom'],
            'description' => ['required', 'string', 'min:2', 'max:1000'],
            'diagnosisType' => ['nullable', 'in:provisional,differential,final'],
            'isPrimary' => ['nullable', 'boolean'],
            'onsetDate' => ['nullable', 'date'],
        ];
    }
}
