<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST transfusions/{transfusion}/complete — complete a dual-verified
 * transfusion (PRODUCT_REQUIREMENTS §6.12).
 */
class CompleteTransfusionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'volumeTransfusedMl' => ['required', 'integer', 'min:1', 'max:1000'],
            'stoppedAt' => ['nullable', 'date'],
        ];
    }
}
