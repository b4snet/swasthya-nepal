<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST blood-units/{unit}/issue — issue a tested, unexpired unit to a
 * patient after a compatible crossmatch (PRODUCT_REQUIREMENTS §6.12).
 */
class IssueBloodUnitRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'string', 'uuid'],
        ];
    }
}
