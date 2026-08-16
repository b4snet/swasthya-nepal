<?php

namespace App\Http\Requests\Hr;

use App\Http\Requests\ApiRequest;

/**
 * POST payroll-exports — generate an audited payroll-ready export for a
 * period (who exported what is recorded; the payload is delivered once).
 */
class GeneratePayrollExportRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'format' => ['sometimes', 'in:payroll_ready,csv'],
        ];
    }
}
