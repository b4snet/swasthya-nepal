<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST blood-units/{unit}/test — test a quarantined unit; passing screening
 * makes it available, failing discards it (PRODUCT_REQUIREMENTS §6.12).
 */
class TestBloodUnitRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'suitable' => ['sometimes', 'boolean'],
            'testResults' => ['sometimes', 'array'],
        ];
    }
}
