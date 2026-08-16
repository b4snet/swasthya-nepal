<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/**
 * POST invoices/{invoice}/claims — build a draft insurance claim from the
 * invoice under the patient's policy. Claim lines map exactly to invoice
 * lines (invoice truth — PRODUCT_REQUIREMENTS §6.14).
 */
class StoreClaimRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'policyId' => ['required', 'string', 'uuid'],
        ];
    }
}
