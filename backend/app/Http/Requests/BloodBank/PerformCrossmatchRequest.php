<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST crossmatches/{crossmatch}/perform — record the compatibility check
 * and set the crossmatch result (PRODUCT_REQUIREMENTS §6.12).
 */
class PerformCrossmatchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientBloodGroup' => ['required', 'in:A,B,AB,O'],
            'patientRhFactor' => ['nullable', 'in:positive,negative'],
            'aboRhCompatible' => ['required', 'boolean'],
            'antibodyScreen' => ['sometimes', 'in:negative,positive'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
