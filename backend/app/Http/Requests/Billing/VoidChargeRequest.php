<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/**
 * POST charges/{charge}/void — void a posted charge (ROADMAP §14, DATABASE.md
 * §3.33). The reason is REQUIRED and recorded on the row (void = status +
 * reason + approver). It is free text that may contain PHI and therefore
 * never reaches audit payloads or the API response — the same discipline as
 * StoreRefundRequest::reasonNote.
 */
class VoidChargeRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
