<?php

namespace App\Http\Requests\Ot;

use App\Http\Requests\ApiRequest;

/**
 * POST procedures/{procedure}/recovery — admit to PACU recovery
 * (PRODUCT_REQUIREMENTS §6.10).
 */
class AdmitToRecoveryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'observations' => ['sometimes', 'array'],
            'admittedAt' => ['nullable', 'date'],
        ];
    }
}
