<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST transfusions/{transfusion}/stop — stop a started transfusion early
 * (e.g. a reaction). The unit cannot be reused (PRODUCT_REQUIREMENTS §6.12).
 */
class StopTransfusionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'volumeTransfusedMl' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'stoppedAt' => ['nullable', 'date'],
        ];
    }
}
