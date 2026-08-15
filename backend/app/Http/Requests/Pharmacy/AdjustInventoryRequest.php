<?php

namespace App\Http\Requests\Pharmacy;

use App\Http\Requests\ApiRequest;

/**
 * POST inventory-items/{item}/adjust — a stock adjustment (count correction,
 * damage, expiry write-off…). The delta is signed and never zero; every
 * adjustment requires a reason because the ledger is the audit of record.
 */
class AdjustInventoryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantityDelta' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
