<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/**
 * POST deposits/{deposit}/allocate — apply part of a deposit to an invoice.
 * The invoice must belong to the deposit's own patient and facility; the
 * allocation can never exceed the deposit's remaining balance.
 */
class AllocateDepositRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoiceId' => ['required', 'string', 'uuid'],
            'amountMinor' => ['required', 'integer', 'min:1'],
        ];
    }
}
