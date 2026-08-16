<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST donors/{donor}/donations — record a donation and process it into
 * componentized blood units (PRODUCT_REQUIREMENTS §6.12).
 */
class RecordDonationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phlebotomistStaffId' => ['required', 'string', 'uuid'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.componentType' => ['required', 'in:whole_blood,packed_cells,plasma,platelets,cryoprecipitate,other'],
            'components.*.expiryDays' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'volumeMl' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'donatedAt' => ['nullable', 'date'],
        ];
    }
}
