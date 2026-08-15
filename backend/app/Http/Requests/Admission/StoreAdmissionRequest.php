<?php

namespace App\Http\Requests\Admission;

use App\Http\Requests\ApiRequest;
use App\Models\Admission;
use Illuminate\Validation\Rule;

/**
 * POST encounters/{encounter}/admissions — admit the encounter's patient to
 * the inpatient ward, assigning a live available bed. The bed is validated
 * in-scope and CAS-claimed in the service (two clerks can never book the
 * same bed). admittingDiagnosis is required for the admission record.
 */
class StoreAdmissionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bedId' => ['required', 'uuid'],
            'admissionType' => ['required', 'string', Rule::in([
                Admission::TYPE_EMERGENCY,
                Admission::TYPE_PLANNED,
                Admission::TYPE_TRANSFER_IN,
            ])],
            'admittingDiagnosis' => ['required', 'string', 'max:1000'],
        ];
    }
}
