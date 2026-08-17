<?php

namespace App\Http\Requests\Rpm;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/rpm/devices — enroll a device adapter against a patient
 * (requires the patient's ACTIVE device_monitoring consent, enforced by
 * RpmService, not the request).
 */
class StoreDeviceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'deviceIdentifier' => ['required', 'string', 'max:120'],
            'readingType' => ['required', 'in:bp,pulse,temp,spo2,glucose,weight'],
            'model' => ['nullable', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'adapter' => ['nullable', 'string', 'max:120'],
            'settings' => ['nullable', 'array'],
            'settings.thresholds' => ['nullable', 'array'],
            'settings.alert_cooldown_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
