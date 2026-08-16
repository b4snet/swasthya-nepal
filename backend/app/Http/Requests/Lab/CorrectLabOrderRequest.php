<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\ApiRequest;

/**
 * POST lab-orders/{labOrder}/correct — open a correction on a reported
 * (immutable) order (Phase 3 slice 15, CLINICAL_SAFETY §7). The reason is
 * captured at initiation, stored on the order, and stamped onto every new
 * result version written by the correction. It is a clinical fact — it is
 * never written to audit payloads (it may contain PHI).
 */
class CorrectLabOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
