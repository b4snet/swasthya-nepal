<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;
use App\Models\RefundRequest;
use Illuminate\Validation\Rule;

/**
 * POST charges/{charge}/refunds — request a refund or adjustment against a
 * posted charge. amountMinor must be positive and the controller validates
 * it does not exceed the charge's refundable amount (amount_minor minus
 * approved reversals). reasonCode is a structured code; reasonNote is free
 * text that may contain PHI and therefore never reaches audit payloads.
 */
class StoreRefundRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amountMinor' => ['required', 'integer', 'min:1'],
            'reasonCode' => ['required', 'string', Rule::in([
                RefundRequest::REASON_OVERCHARGE,
                RefundRequest::REASON_DUPLICATE_CHARGE,
                RefundRequest::REASON_SERVICE_NOT_RENDERED,
                RefundRequest::REASON_PATIENT_REQUEST,
                RefundRequest::REASON_ADJUSTMENT,
                RefundRequest::REASON_OTHER,
            ])],
            'reasonNote' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
