<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiRequest;

/**
 * POST analytics/report-templates — define a parameterized report. The
 * query is a whitelisted structure (source_table, filter, date_column,
 * period, optional group_by / aggregation / sum_column) — never raw SQL.
 */
class StoreReportTemplateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:operational,financial,clinical,executive'],
            'scope' => ['required', 'in:tenant,facility,branch'],
            'query' => ['required', 'array'],
            'query.sourceTable' => ['required', 'string', 'max:100'],
            'query.filter' => ['sometimes', 'array'],
            'query.dateColumn' => ['nullable', 'string', 'max:50'],
            'query.period' => ['sometimes', 'in:today,yesterday,last_7_days,last_30_days,this_month,custom'],
            'query.groupBy' => ['nullable', 'string', 'max:50'],
            'query.aggregation' => ['sometimes', 'in:count,sum'],
            'query.sumColumn' => ['nullable', 'string', 'max:50'],
            'parameterSchema' => ['sometimes', 'array'],
        ];
    }
}
