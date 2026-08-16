<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST blood-units/{unit}/discard — discard a unit with reason (expiry,
 * contamination, recall — PRODUCT_REQUIREMENTS §6.12). Terminal.
 */
class DiscardBloodUnitRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
