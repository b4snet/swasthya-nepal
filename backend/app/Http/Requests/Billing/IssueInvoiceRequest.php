<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/**
 * POST encounters/{encounter}/invoice — build the bill. When chargeIds is
 * empty the server DERIVES the charges from the encounter (service
 * consultation charge + prescription line charges) and posts them; when
 * provided, the given posted charges are invoiced directly.
 */
class IssueInvoiceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'chargeIds' => ['nullable', 'array'],
            'chargeIds.*' => ['required', 'uuid'],
        ];
    }
}
