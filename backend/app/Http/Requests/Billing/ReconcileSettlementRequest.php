<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/**
 * POST cashier-settlements/reconcile — reconcile a cashier's day:
 * actualMinor is the counted cash/float; expected is derived from the day's
 * captured payments. A non-zero variance disputes (never silently absorbed).
 */
class ReconcileSettlementRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cashierId' => ['nullable', 'string', 'uuid'],
            'settlementDate' => ['nullable', 'date', 'date_format:Y-m-d'],
            'actualMinor' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
