<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiRequest;

/**
 * POST analytics/snapshots/refresh — recompute the operational metric from
 * the REAL source table (observed data only) for the requested period.
 */
class RefreshMetricsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kpiDefinitionId' => ['required', 'uuid'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'dimension' => ['sometimes', 'array'],
        ];
    }
}
