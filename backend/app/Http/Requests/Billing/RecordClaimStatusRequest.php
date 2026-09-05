<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;
use App\Models\InsuranceClaim;
use Illuminate\Validation\Rule;

/**
 * POST claims/{claim}/status — record a payer status update on a
 * submitted/pending claim. A denial requires a denial reason; a rejection
 * (claim not accepted for processing, §25) requires its own rejection
 * reason — kept distinct from denial. Paid/partial record the payer
 * settlement (never more than the claim's billed total).
 */
class RecordClaimStatusRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                InsuranceClaim::STATUS_PENDING,
                InsuranceClaim::STATUS_PARTIAL,
                InsuranceClaim::STATUS_PAID,
                InsuranceClaim::STATUS_DENIED,
                InsuranceClaim::STATUS_REJECTED,
            ])],
            'denialReason' => ['nullable', 'string', 'max:1000'],
            'rejectionReason' => ['nullable', 'string', 'max:1000'],
            'settlementMinor' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
