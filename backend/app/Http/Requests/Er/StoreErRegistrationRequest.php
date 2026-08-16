<?php

namespace App\Http\Requests\Er;

use App\Http\Requests\ApiRequest;
use App\Models\Patient;
use Illuminate\Validation\Rule;

/**
 * POST er/registrations — minimal-data ER registration (PRODUCT_REQUIREMENTS
 * §6.6): speed over completeness. Name/age/sex are optional; a fully
 * unidentified patient is registered with the documented placeholder and a
 * sentinel DOB, and identity is later resolved via the patient-merge
 * controlled link. dateOfBirth and estimatedAge are mutually exclusive.
 */
class StoreErRegistrationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facilityId' => ['sometimes', 'uuid'],
            'patientName' => ['sometimes', 'string', 'max:255'],
            'sex' => ['sometimes', 'string', Rule::in([
                Patient::SEX_MALE,
                Patient::SEX_FEMALE,
                Patient::SEX_OTHER,
                Patient::SEX_UNKNOWN,
            ])],
            'dateOfBirth' => ['sometimes', 'date', 'before:today', 'prohibits:estimatedAge'],
            'estimatedAge' => ['sometimes', 'integer', 'min:0', 'max:150', 'prohibits:dateOfBirth'],
            'presentingComplaint' => ['sometimes', 'string', 'max:2000'],
        ];
    }
}
