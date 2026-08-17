<?php

namespace App\Http\Requests\Pharmacy;

use App\Http\Requests\ApiRequest;

/**
 * POST /dispensings — STANDALONE dispensing (PRODUCT_REQUIREMENTS §6.7
 * stock-out: dispensing without a prescription; DATABASE.md §3.30). A
 * pharmacist dispenses a medication directly to a patient: patientId and
 * medicationId are required (an unlinked dispensing is never valid),
 * quantityMinor must be ≥ 1, and batchId is OPTIONAL — when absent the
 * system picks FEFO among available, unexpired batches (the same selection
 * rule as prescription dispensing). Cross-checks (patient/medication scope,
 * stock, expiry, controlled-substance policy) happen in the service under
 * the row locks, never here.
 */
class StoreDispensingRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'medicationId' => ['required', 'uuid'],
            'quantityMinor' => ['required', 'integer', 'min:1'],
            'batchId' => ['nullable', 'uuid'],
        ];
    }
}
