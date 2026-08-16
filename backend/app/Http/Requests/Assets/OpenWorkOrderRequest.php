<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\ApiRequest;

/**
 * POST work-orders — open maintenance work on an asset. When downtime is
 * tracked, the asset moves to under_repair in the same transaction.
 */
class OpenWorkOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assetId' => ['required', 'string', 'uuid'],
            'maintenanceScheduleId' => ['nullable', 'string', 'uuid'],
            'downtimeStartedAt' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
