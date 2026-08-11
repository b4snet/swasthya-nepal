<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/patients/{patient}/documents — register document METADATA.
 *
 * Object storage does not exist yet, so the record is honestly `staged`
 * with no object key; it becomes `available` when the storage integration
 * lands. No endpoint pretends a file can be downloaded.
 */
class StoreDocumentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documentType' => ['required', 'in:consent,id,referral,report,discharge,other'],
            'mimeType' => ['required', 'string', 'max:100'],
            'sizeBytes' => ['nullable', 'integer', 'min:0'],
            'checksum' => ['nullable', 'string', 'max:128'],
            'expiresAt' => ['nullable', 'date'],
            'retentionClass' => ['nullable', 'string', 'max:50'],
        ];
    }
}
