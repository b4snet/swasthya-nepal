<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/**
 * POST patients/{patient}/deposits — collect an advance payment (deposit)
 * on the patient account. Idempotent per idempotencyKey (DATABASE.md §3.33,
 * MASTER_RULES §37).
 */
class StoreDepositRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amountMinor' => ['required', 'integer', 'min:1'],
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
