<?php

namespace App\Http\Requests\Encounter;

use App\Http\Requests\ApiRequest;

/**
 * POST encounters/{encounter}/discharge — the clinical close of a signed
 * encounter: disposition + structured discharge summary. Discharge requires
 * clinical authority (the encounter provider, gate: encounter:sign).
 */
class DischargeEncounterRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'disposition' => ['required', 'string', 'in:home,admitted,referred,deceased'],
            'summary' => ['required', 'string', 'max:5000'],
        ];
    }
}
