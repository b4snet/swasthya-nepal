<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\ApiRequest;

/**
 * POST /vendors — create a vendor. Credentials (tax id, bank details) are
 * encrypted at rest and never logged or echoed.
 */
class StoreVendorRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facilityId' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
            'taxId' => ['nullable', 'string', 'max:100'],
            'bankDetails' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
