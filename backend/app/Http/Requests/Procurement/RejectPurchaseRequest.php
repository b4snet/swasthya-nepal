<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\ApiRequest;

/**
 * POST /purchase-requests/{request}/reject — the approver's decline with a
 * required reason (terminal; PHI-safe — never echoed or audited).
 */
class RejectPurchaseRequest extends ApiRequest
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
