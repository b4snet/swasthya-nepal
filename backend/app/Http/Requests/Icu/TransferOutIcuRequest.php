<?php

namespace App\Http\Requests\Icu;

use App\Http\Requests\ApiRequest;

/**
 * POST icu-admissions/{admission}/transfer — step down / discharge from ICU
 * with handover documentation (PRODUCT_REQUIREMENTS §6.11).
 */
class TransferOutIcuRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'handoverNotes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
