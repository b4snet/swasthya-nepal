<?php

namespace App\Http\Requests\Pharmacy;

use App\Http\Requests\ApiRequest;

/**
 * POST organizations/{organization}/inventory — stock a medication into a
 * facility's inventory (a receipt). The medication and facility must belong
 * to the organization; that ownership is verified in the controller. A
 * receipt is always a positive quantity — adjustments live on the dedicated
 * adjust endpoint.
 */
class StoreInventoryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facilityId' => ['required', 'uuid'],
            'medicationId' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reorderLevel' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
