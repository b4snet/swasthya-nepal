<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;

/**
 * PATCH /api/v1/patients/{patient} — demographic corrections.
 *
 * lockVersion is the optimistic-locking guard (DATABASE.md §0.7): a stale
 * value is rejected with 409 LOCK_CONFLICT, never silently overwritten.
 */
class UpdatePatientRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fullName' => ['sometimes', 'string', 'min:2', 'max:255'],
            'dateOfBirth' => ['sometimes', 'date', 'before:today'],
            'sex' => ['sometimes', 'in:male,female,other,unknown'],
            'bloodGroup' => ['sometimes', 'nullable', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'lockVersion' => ['required', 'integer', 'min:0'],
        ];
    }
}
