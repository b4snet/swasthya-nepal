<?php

namespace App\Http\Requests\Cdss;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/cdss/checks/prescription — run the knowledge-base checks
 * (allergy, drug-drug interaction, dose) against a PROPOSED prescription.
 * The check mutates nothing clinical — it persists only the raised alerts.
 */
class CheckPrescriptionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.medicationId' => ['required', 'uuid'],
            'lines.*.dose' => ['required', 'string', 'max:100'],
            'lines.*.route' => ['required', 'string', 'max:50'],
            'lines.*.frequency' => ['required', 'string', 'max:50'],
        ];
    }
}
