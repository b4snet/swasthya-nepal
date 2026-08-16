<?php

namespace App\Http\Requests\BloodBank;

use App\Http\Requests\ApiRequest;

/**
 * POST transfusions/{transfusion}/reaction — report a transfusion reaction
 * (PRODUCT_REQUIREMENTS §6.12).
 */
class ReportReactionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'severity' => ['required', 'in:mild,moderate,severe'],
            'symptoms' => ['sometimes', 'array'],
            'actionTaken' => ['nullable', 'string', 'max:2000'],
            'occurredAt' => ['nullable', 'date'],
        ];
    }
}
