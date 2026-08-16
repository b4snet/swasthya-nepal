<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\ApiRequest;

/**
 * POST assets/{asset}/iot-readings — record an RFID/IoT-ready reading
 * (location/condition/usage). The data model is designed now; device feeds
 * arrive in Phase 3 with a real integration. Manual readings exercise the
 * model end to end without faking a device.
 */
class StoreIotReadingRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'readingType' => ['required', 'in:location,condition,usage'],
            'readingValue' => ['required', 'array'],
            'tagId' => ['nullable', 'string', 'max:100'],
            'readAt' => ['nullable', 'date'],
            'source' => ['sometimes', 'in:rfid,device,manual'],
        ];
    }
}
