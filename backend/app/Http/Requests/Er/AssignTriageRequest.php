<?php

namespace App\Http\Requests\Er;

use App\Http\Requests\ApiRequest;

/**
 * POST er/encounters/{encounter}/triage — assign (or reassign) the acuity
 * level from the configurable scale. Providing overrideReason is the
 * clinical-authority OVERRIDE path (PRODUCT_REQUIREMENTS §6.6: "triage
 * override requires clinical authority and is audited") — the controller
 * refuses it unless the actor holds er:disposition.
 */
class AssignTriageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scaleId' => ['required', 'uuid'],
            'overrideReason' => ['sometimes', 'string', 'max:1000'],
        ];
    }
}
