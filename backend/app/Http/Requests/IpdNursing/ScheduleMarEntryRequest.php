<?php

namespace App\Http\Requests\IpdNursing;

use App\Http\Requests\ApiRequest;

/**
 * POST admissions/{admission}/mar — schedule a dose of a prescription line
 * on the admission's MAR at its due time. One entry per (line, scheduled
 * time) is DB-enforced (uq_mar_entries_tenant_line_scheduled); duplicates
 * return 409.
 */
class ScheduleMarEntryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prescriptionLineId' => ['required', 'uuid'],
            'scheduledAt' => ['required', 'date', 'date_format:Y-m-d\TH:i:sP'],
        ];
    }
}
