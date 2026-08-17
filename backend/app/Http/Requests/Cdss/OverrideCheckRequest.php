<?php

namespace App\Http\Requests\Cdss;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/cdss/checks/{cdssCheckResult}/override — record the
 * prescriber's override of an open check result. The reason is MANDATORY
 * and audited: an override is never a silent dismiss (AI_RULES.md §7,
 * CLINICAL_SAFETY.md §5).
 */
class OverrideCheckRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
