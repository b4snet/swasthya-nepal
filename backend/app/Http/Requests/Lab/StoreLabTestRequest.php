<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\ApiRequest;

/**
 * POST organizations/{organization}/lab-tests — catalog entry. The catalog
 * is the reference for ordering; retired tests are soft-deleted, never
 * removed (order history stays intact).
 */
class StoreLabTestRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facilityId' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'category' => ['nullable', 'string', 'in:laboratory,hematology,biochemistry,microbiology,immunology,pathology,radiology,ultrasound,other'],
            'sampleType' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:20'],
            'referenceRange' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'max:255'],
        ];
    }
}
