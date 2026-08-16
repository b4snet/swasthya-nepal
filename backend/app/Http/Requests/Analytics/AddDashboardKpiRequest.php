<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiRequest;

/**
 * POST analytics/dashboards/{dashboard}/kpis — place a KPI on a dashboard
 * at an ordered position (one active slot per position).
 */
class AddDashboardKpiRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kpiDefinitionId' => ['required', 'uuid'],
            'position' => ['required', 'integer', 'min:1'],
        ];
    }
}
