<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\ApiRequest;

/**
 * POST maintenance-schedules — schedule recurring maintenance for an asset.
 */
class StoreMaintenanceScheduleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assetId' => ['required', 'string', 'uuid'],
            'scheduleType' => ['required', 'in:preventive,contract,certification'],
            'frequencyDays' => ['required', 'integer', 'min:1'],
            'nextDueDate' => ['required', 'date'],
            'contractRef' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
