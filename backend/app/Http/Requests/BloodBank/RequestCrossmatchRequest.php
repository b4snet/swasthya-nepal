<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST blood-units/{unit}/crossmatch — request a crossmatch of one unit
 * against one patient (PRODUCT_REQUIREMENTS §6.12).
 */
class RequestCrossmatchRequest extends ApiRequest
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
