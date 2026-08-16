<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;
use App\Models\InsuranceClaim;
use Illuminate\Validation\Rule;

/**
 * POST claims/{claim}/settle — record the payer settlement for a
 * submitted/pending claim (partial | paid with a settlement amount never
 * exceeding the claim's billed total). Gated by insurance:settle —
 * segregation of duties from the clerk surface that builds and submits.
 */
class SettleClaimRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                InsuranceClaim::STATUS_PARTIAL,
                InsuranceClaim::STATUS_PAID,
            ])],
            'settlementMinor' => ['required', 'integer', 'min:0'],
        ];
    }
}
