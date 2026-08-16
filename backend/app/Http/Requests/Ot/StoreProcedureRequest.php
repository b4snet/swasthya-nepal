<?php

namespace App\Http\Requests\Ot;

use App\Http\Requests\ApiRequest;

/**
 * POST procedure-requests — request a surgical procedure
 * (PRODUCT_REQUIREMENTS §6.10).
 */
class StoreProcedureRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'string', 'uuid'],
            'encounterId' => ['nullable', 'string', 'uuid'],
            'procedureName' => ['required', 'string', 'max:255'],
            'priority' => ['sometimes', 'in:routine,urgent,emergency'],
        ];
    }
}
