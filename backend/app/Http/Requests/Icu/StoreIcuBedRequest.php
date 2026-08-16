<?php

namespace App\Http\Requests\Icu;

use App\Http\Requests\ApiRequest;

/**
 * POST icu-beds — create an ICU bed with acuity support
 * (PRODUCT_REQUIREMENTS §6.11).
 */
class StoreIcuBedRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bedCode' => ['required', 'string', 'max:20'],
            'acuitySupported' => ['sometimes', 'in:level_1,level_2,level_3'],
            'status' => ['sometimes', 'in:available,occupied,reserved,out_of_service'],
        ];
    }
}
