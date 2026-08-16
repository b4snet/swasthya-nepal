<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiRequest;

/**
 * POST analytics/kpi-definitions — create a versioned metric definition
 * (PRODUCT REQUIREMENTS §6.19). The source table / date column / filter /
 * aggregation / sum column combination is validated against the service
 * whitelist — a definition can never read an unlisted column.
 */
class StoreKpiDefinitionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'in:operational,financial,clinical,executive'],
            'sourceTable' => ['required', 'string', 'max:100'],
            'dateColumn' => ['nullable', 'string', 'max:50'],
            'filter' => ['sometimes', 'array'],
            'aggregation' => ['required', 'in:count,sum'],
            'sumColumn' => ['nullable', 'string', 'max:50'],
            'unit' => ['nullable', 'string', 'max:30'],
        ];
    }
}
