<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\ApiRequest;

/**
 * POST asset-categories — create an asset category.
 */
class StoreAssetCategoryRequest extends ApiRequest
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
