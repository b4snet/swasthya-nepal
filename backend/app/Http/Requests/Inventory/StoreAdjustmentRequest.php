<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ApiRequest;

/**
 * POST /inventory-items/{item}/adjustment-requests — request an
 * approval-gated stock adjustment (cycle count / correction): a signed,
 * non-zero delta with a mandatory reason.
 */
class StoreAdjustmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantityDelta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
