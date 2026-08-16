<?php

namespace App\Http\Requests\Ot;

use App\Http\Requests\ApiRequest;

/**
 * POST theatres — create an operating theatre in the facility catalog
 * (PRODUCT_REQUIREMENTS §6.10).
 */
class StoreTheatreRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
