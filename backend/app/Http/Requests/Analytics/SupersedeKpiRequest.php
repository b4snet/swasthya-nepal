<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiRequest;

/**
 * POST analytics/kpi-definitions/{kpi}/supersede — publish a new version of
 * a metric definition ("a changing KPI is not a KPI"). The old ACTIVE
 * version is preserved as superseded; the new version is validated against
 * the same whitelist before it can become active.
 */
class SupersedeKpiRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'domain' => ['sometimes', 'in:operational,financial,clinical,executive'],
            'sourceTable' => ['sometimes', 'string', 'max:100'],
            'dateColumn' => ['nullable', 'string', 'max:50'],
            'filter' => ['sometimes', 'array'],
            'aggregation' => ['sometimes', 'in:count,sum'],
            'sumColumn' => ['nullable', 'string', 'max:50'],
            'unit' => ['nullable', 'string', 'max:30'],
        ];
    }
}
