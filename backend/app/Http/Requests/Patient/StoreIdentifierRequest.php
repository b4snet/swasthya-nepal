<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/patients/{patient}/identifiers.
 *
 * The value is encrypted at rest and hashed for duplicate detection
 * (DATABASE.md §3.12). An identifier already active on ANOTHER patient
 * surfaces a duplicate (409 RESOURCE_EXISTS) — never silently re-used.
 */
class StoreIdentifierRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:national_id,passport,license,other'],
            'value' => ['required', 'string', 'min:3', 'max:255'],
            'issuingCountry' => ['nullable', 'string', 'max:100'],
        ];
    }
}
