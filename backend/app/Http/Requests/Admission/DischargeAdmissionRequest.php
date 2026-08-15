<?php

namespace App\Http\Requests\Admission;

use App\Http\Requests\ApiRequest;
use App\Models\Admission;
use Illuminate\Validation\Rule;

/**
 * POST admissions/{admission}/discharge — the clinical close of an inpatient
 * stay: discharge_type + the structured discharge summary (diagnoses,
 * procedures, medications, follow-up sections — stored as a signed clinical
 * note of type 'discharge', never in audit payloads). The bed is released
 * atomically in the same transaction.
 */
class DischargeAdmissionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dischargeType' => ['required', 'string', Rule::in([
                Admission::DISCHARGE_HOME,
                Admission::DISCHARGE_REFERRAL,
                Admission::DISCHARGE_TRANSFER_OUT,
                Admission::DISCHARGE_AGAINST_ADVICE,
            ])],
            'summary' => ['required', 'array'],
            'summary.diagnoses' => ['sometimes', 'array', 'max:50'],
            'summary.diagnoses.*' => ['string', 'max:500'],
            'summary.procedures' => ['sometimes', 'array', 'max:50'],
            'summary.procedures.*' => ['string', 'max:500'],
            'summary.medications' => ['sometimes', 'array', 'max:100'],
            'summary.medications.*' => ['string', 'max:500'],
            'summary.followUp' => ['sometimes', 'string', 'max:2000'],
        ];
    }
}
