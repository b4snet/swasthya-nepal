<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiRequest;

/**
 * POST analytics/report-schedules — schedule a report template on a
 * 5-field cron expression. The expression is validated at creation time
 * (an invalid cron fails now, not silently at 3am).
 */
class StoreReportScheduleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'templateId' => ['required', 'uuid'],
            'cronExpression' => ['required', 'string', 'max:100'],
        ];
    }
}
