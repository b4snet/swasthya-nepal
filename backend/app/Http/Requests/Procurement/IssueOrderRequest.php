<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\ApiRequest;

/**
 * POST /purchase-orders — issue a PO from an approved purchase request
 * against a vendor (contract prices enforced at issue).
 */
class IssueOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facilityId' => ['required', 'uuid'],
            'requestId' => ['required', 'uuid'],
            'vendorId' => ['required', 'uuid'],
            'expectedDelivery' => ['nullable', 'date'],
        ];
    }
}
