<?php

namespace App\Http\Requests\Medication;

use App\Http\Requests\ApiRequest;

/**
 * POST medications — formulary entry. Price is integer minor units,
 * never floats (DATABASE.md §0.4).
 */
class StoreMedicationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facilityId' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:50'],
            'genericName' => ['required', 'string', 'min:2', 'max:255'],
            'brandName' => ['nullable', 'string', 'max:255'],
            'strength' => ['required', 'string', 'max:100'],
            'form' => ['nullable', 'string', 'max:50'],
            'unit' => ['required', 'string', 'max:50'],
            'priceMinor' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isControlled' => ['nullable', 'boolean'],
        ];
    }
}
