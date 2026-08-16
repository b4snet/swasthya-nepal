<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\ApiRequest;

/**
 * POST assets — register an asset in the register (lifecycle: procured).
 */
class StoreAssetRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categoryId' => ['required', 'string', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'serialNumber' => ['nullable', 'string', 'max:100'],
            'rfidTag' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'currentLocationId' => ['nullable', 'string', 'uuid'],
            'purchaseValueMinor' => ['nullable', 'integer', 'min:0'],
            'purchaseDate' => ['nullable', 'date'],
            'warrantyUntil' => ['nullable', 'date', 'after_or_equal:purchaseDate'],
        ];
    }
}
