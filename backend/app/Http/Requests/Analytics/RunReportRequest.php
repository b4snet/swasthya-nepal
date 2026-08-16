<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiRequest;

/**
 * POST analytics/reports/run — execute a report template now against the
 * reporting (read-replica) connection. Optional export flag (reports:export
 * gate is enforced separately by the route).
 */
class RunReportRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'templateId' => ['required', 'uuid'],
            'parameters' => ['sometimes', 'array'],
            'export' => ['sometimes', 'boolean'],
            'exportFormat' => ['sometimes', 'in:csv,pdf'],
        ];
    }
}
