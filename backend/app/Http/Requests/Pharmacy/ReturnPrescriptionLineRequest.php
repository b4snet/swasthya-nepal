<?php

namespace App\Http\Requests\Pharmacy;

use App\Http\Requests\ApiRequest;
use App\Models\PharmacyReturn;
use Illuminate\Validation\Rule;

/**
 * POST prescription-lines/{line}/return — return part or all of a dispensed
 * line. `quantityMinor` is OPTIONAL: when absent, the FULL remaining
 * returnable quantity is returned (the slice-8 whole-line behavior — a
 * backward-compatible default). When present, it must be > 0 (zero/negative
 * is rejected here) and the service re-checks it against the line's
 * remaining returnable quantity under the row lock (an over-return is
 * refused there with CONFLICT, never here — the remaining quantity is
 * state). reasonCode is a structured code; reasonNote is free text that may
 * contain PHI and therefore never reaches audit payloads.
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
            'quantityMinor' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
