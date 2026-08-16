<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST donors — register a blood donor (PRODUCT_REQUIREMENTS §6.12).
 * Personal data is protected to the same standard as patient data.
 */
class StoreDonorRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'donorNumber' => ['required', 'string', 'max:50'],
            'fullName' => ['required', 'string', 'max:255'],
            'dateOfBirth' => ['required', 'date', 'before:today'],
            'sex' => ['nullable', 'string', 'max:20'],
            'bloodGroup' => ['nullable', 'in:A,B,AB,O'],
            'rhFactor' => ['nullable', 'in:positive,negative'],
            'phone' => ['nullable', 'string', 'max:30'],
            'screening' => ['sometimes', 'array'],
        ];
    }
}
