<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ApiRequest;

/**
 * POST /inventory-transfers — an inter-facility stock transfer: the source
 * item is CAS-decremented and the destination item CAS-incremented in one
 * atomic transaction with paired ledger movements.
 */
class StoreTransferRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'inventoryItemId' => ['required', 'uuid'],
            'destinationFacilityId' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
