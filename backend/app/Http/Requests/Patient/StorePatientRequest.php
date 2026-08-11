<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasFacilityContext;

/**
 * POST /api/v1/patients — new-patient registration.
 *
 * Search first is the front-desk workflow; this endpoint runs duplicate
 * detection server-side and returns candidates in meta.duplicates (never
 * auto-merge, API_CONTRACTS.md §21.7). Demographics are required; phone /
 * email / address / identifiers / emergency contact are captured with the
 * registration.
 */
class StorePatientRequest extends ApiRequest
{
    use HasFacilityContext;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fullName' => ['required', 'string', 'min:2', 'max:255'],
            'dateOfBirth' => ['required', 'date', 'before:today'],
            'sex' => ['required', 'in:male,female,other,unknown'],
            'bloodGroup' => ['nullable', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'facilityId' => $this->facilityIdRules(),
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'array'],
            'emergencyContact' => ['nullable', 'array'],
            'emergencyContact.name' => ['required_with:emergencyContact', 'string', 'min:2', 'max:255'],
            'emergencyContact.relation' => ['required_with:emergencyContact', 'string', 'max:100'],
            'emergencyContact.phone' => ['required_with:emergencyContact', 'string', 'max:50'],
            'identifiers' => ['nullable', 'array', 'max:10'],
            'identifiers.*.type' => ['required_with:identifiers', 'in:national_id,passport,license,other'],
            'identifiers.*.value' => ['required_with:identifiers', 'string', 'min:3', 'max:255'],
            'identifiers.*.issuingCountry' => ['nullable', 'string', 'max:100'],
        ];
    }
}
