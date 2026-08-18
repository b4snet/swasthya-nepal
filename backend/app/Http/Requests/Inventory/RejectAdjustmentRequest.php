<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ApiRequest;

/**
 * POST /inventory-adjustment-requests/{request}/reject — the approver's
 * decline with a required reason (terminal).
 */
class RejectAdjustmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejectionReason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
