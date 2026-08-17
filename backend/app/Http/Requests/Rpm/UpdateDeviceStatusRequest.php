<?php

namespace App\Http\Requests\Rpm;

use App\Http\Requests\ApiRequest;

/**
 * PATCH /api/v1/rpm/devices/{device} — activate/disable an enrolled device.
 * CAS-guarded in RpmService (pending → active ⇄ disabled).
 */
class UpdateDeviceStatusRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:active,disabled'],
        ];
    }
}
