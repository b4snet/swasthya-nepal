<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/**
 * POST refund-requests/{refundRequest}/reject — an approver declines a
 * pending request. rejectionReason is mandatory so every rejection is
 * explainable (and audited as a fact, never the free text itself).
 */
class RejectRefundRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejectionReason' => ['required', 'string', 'max:1000'],
        ];
    }
}
