<?php

namespace App\Http\Requests\Interop;

use App\Http\Requests\ApiRequest;
use App\Models\Integration;

/**
 * POST /api/v1/interop/integrations/{integration}/status — records a
 * MEASURED status observation (probe/manual check), never an assertion
 * (INTEROPERABILITY.md §9, §13).
 */
class RecordIntegrationStatusRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', [
                Integration::STATUS_CONFIGURED, Integration::STATUS_ACTIVE,
                Integration::STATUS_DEGRADED, Integration::STATUS_DISABLED,
            ])],
            'health' => ['nullable', 'array'],
            'health.latencyMs' => ['nullable', 'integer', 'min:0'],
            'health.errorRate' => ['nullable', 'numeric', 'between:0,1'],
            'health.lastError' => ['nullable', 'string', 'max:500'],
        ];
    }
}
