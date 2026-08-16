<?php

namespace App\Http\Requests\Ot;

use App\Http\Requests\ApiRequest;

/**
 * POST procedures/{procedure}/anesthesia — record the anesthesia record
 * (PRODUCT_REQUIREMENTS §6.10).
 */
class StartAnesthesiaRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'anesthetistStaffId' => ['required', 'string', 'uuid'],
            'anesthesiaType' => ['required', 'in:general,regional,spinal,local,sedation,other'],
            'startedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
