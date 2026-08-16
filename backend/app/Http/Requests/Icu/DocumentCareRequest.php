<?php

namespace App\Http\Requests\Icu;

use App\Http\Requests\ApiRequest;

/**
 * POST icu-admissions/{admission}/notes — critical-care documentation
 * (daily goals, sedation scales, weaning plans, procedures —
 * PRODUCT_REQUIREMENTS §6.11).
 */
class DocumentCareRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'noteType' => ['required', 'in:daily_goal,sedation_scale,weaning_plan,procedure,other'],
            'content' => ['required', 'string', 'max:8000'],
            'authoredAt' => ['nullable', 'date'],
        ];
    }
}
