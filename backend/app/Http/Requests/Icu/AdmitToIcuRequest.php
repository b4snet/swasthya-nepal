<?php

namespace App\Http\Requests\Icu;

use App\Http\Requests\ApiRequest;

/**
 * POST icu-admissions — admit a patient to the ICU with acuity-based bed
 * assignment and a policy-defined observation schedule
 * (PRODUCT_REQUIREMENTS §6.11).
 */
class AdmitToIcuRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'string', 'uuid'],
            'icuBedId' => ['required', 'string', 'uuid'],
            'admissionId' => ['nullable', 'string', 'uuid'],
            'source' => ['sometimes', 'in:ipd,er,ot'],
            'acuity' => ['sometimes', 'in:level_1,level_2,level_3'],
            'observationIntervalMinutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'handoverNotes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
