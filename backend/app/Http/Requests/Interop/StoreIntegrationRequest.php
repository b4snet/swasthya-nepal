<?php

namespace App\Http\Requests\Interop;

use App\Http\Requests\ApiRequest;
use App\Models\Integration;

/**
 * POST /api/v1/interop/integrations (DATABASE.md §3.42, INTEROPERABILITY.md
 * §13–14). Registering an integration is a readiness/registry action — the
 * entry records what is connected and its contract versions; a real
 * connection is never implied by this row alone.
 */
class StoreIntegrationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', [
                Integration::TYPE_PAYMENT, Integration::TYPE_SMS, Integration::TYPE_EMAIL,
                Integration::TYPE_LAB, Integration::TYPE_PACS, Integration::TYPE_FHIR,
                Integration::TYPE_HL7, Integration::TYPE_DICOM, Integration::TYPE_NATIONAL,
            ])],
            'provider' => ['required', 'string', 'max:100'],
            'purpose' => ['required', 'string', 'min:10', 'max:500'],
            'contractVersion' => ['required', 'string', 'max:50'],
            'standardsVersion' => ['nullable', 'string', 'max:50'],
            'mappingVersion' => ['nullable', 'string', 'max:50'],
            'ownerStaffId' => ['nullable', 'uuid'],
            'configEncrypted' => ['nullable', 'array'],
        ];
    }
}
