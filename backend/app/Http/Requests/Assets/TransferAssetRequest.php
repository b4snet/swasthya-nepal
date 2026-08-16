<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\ApiRequest;

/**
 * POST assets/{asset}/transfer — move an asset to another location
 * (append-only location history).
 */
class TransferAssetRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'toLocationId' => ['required', 'string', 'uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
