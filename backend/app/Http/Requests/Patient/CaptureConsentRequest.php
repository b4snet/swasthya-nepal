<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/patients/{patient}/consents — capture a new consent version.
 * A new capture expires the prior active version of the same type
 * (one active consent per type, DATABASE.md §3.39).
 */
class CaptureConsentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'consentType' => ['required', 'in:treatment,data_use,telehealth,device_monitoring,marketing,research'],
            'scope' => ['nullable', 'array'],
            'givenAt' => ['nullable', 'date'],
        ];
    }
}
