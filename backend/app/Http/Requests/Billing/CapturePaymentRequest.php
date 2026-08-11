<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/**
 * POST invoices/{invoice}/pay — capture a payment against the invoice.
 * idempotencyKey makes retries safe (DATABASE.md §3.34, MASTER_RULES §37).
 */
class CapturePaymentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'in:cash,card,wallet,bank,insurance'],
            'amountMinor' => ['required', 'integer', 'min:1'],
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:100'],
            'providerRef' => ['nullable', 'string', 'max:100'],
        ];
    }
}
