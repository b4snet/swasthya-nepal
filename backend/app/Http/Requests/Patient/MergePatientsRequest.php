<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/patients/{patient}/merge — the only identity-resolution path
 * (DATABASE.md §3.11). Requires an explicit reason; merge is transactional
 * and fully audited. Never automatic (API_CONTRACTS.md §21.7).
 */
class MergePatientsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'targetPatientId' => ['required', 'uuid', 'exists:patients,id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
