<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\ApiRequest;

/**
 * POST /purchase-requests — a department purchase request with lines
 * (medication, quantity, estimated unit price in integer minor units).
 */
class StorePurchaseRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.medicationId' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.estimatedUnitPriceMinor' => ['required', 'integer', 'min:0'],
            'facilityId' => ['required', 'uuid'],
            'departmentId' => ['nullable', 'uuid'],
        ];
    }
}
