<?php

namespace App\Http\Requests\Pharmacy;

use App\Http\Requests\ApiRequest;
use App\Models\PharmacyReturn;
use Illuminate\Validation\Rule;

/**
 * POST prescription-lines/{line}/return — reverse a dispensed line. The
 * returned quantity is the line's dispensed quantity (a full-line reversal —
 * the charge is one price × quantity per line, so partial returns cannot be
 * expressed without splitting the charge). reasonCode is a structured code;
 * reasonNote is free text that may contain PHI and therefore never reaches
 * audit payloads.
 */
class ReturnPrescriptionLineRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reasonCode' => ['required', 'string', Rule::in([
                PharmacyReturn::REASON_PATIENT_RETURN,
                PharmacyReturn::REASON_WRONG_MEDICATION,
                PharmacyReturn::REASON_ADVERSE_REACTION,
                PharmacyReturn::REASON_DISPENSING_ERROR,
                PharmacyReturn::REASON_DUPLICATE_DISPENSE,
                PharmacyReturn::REASON_OTHER,
            ])],
            'reasonNote' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
