<?php

namespace App\Http\Requests\Er;

use App\Http\Requests\ApiRequest;
use App\Models\Encounter;
use Illuminate\Validation\Rule;

/**
 * POST er/encounters/{encounter}/disposition — the audited ER disposition
 * (PRODUCT_REQUIREMENTS §6.6): admit to IPD (bed CAS-claimed), transfer to
 * another facility (documentation in the event), discharge with
 * instructions, or deceased. notes carries the transfer documentation /
 * discharge instructions — clinical PHI, never audit payloads.
 */
class ErDispositionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'disposition' => ['required', 'string', Rule::in([
                Encounter::DISPOSITION_ADMITTED,
                Encounter::DISPOSITION_REFERRED,
                Encounter::DISPOSITION_HOME,
                Encounter::DISPOSITION_DECEASED,
            ])],
            'notes' => ['sometimes', 'string', 'max:2000'],
            'bedId' => ['required_if:disposition,admitted', 'uuid'],
            'admittingDiagnosis' => ['sometimes', 'string', 'max:1000'],
        ];
    }
}
