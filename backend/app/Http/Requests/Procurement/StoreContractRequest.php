<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * POST /vendors/{vendor}/contracts — a negotiated unit price for a
 * medication with a validity window. PO prices are checked against the
 * active contract at issue.
 */
class StoreContractRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'medicationId' => ['required', 'uuid'],
            'unitPriceMinor' => ['required', 'integer', 'min:0'],
            'validFrom' => ['required', 'date'],
            'validTo' => ['required', 'date', 'after_or_equal:validFrom'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['active', 'expired'])],
        ];
    }
}
