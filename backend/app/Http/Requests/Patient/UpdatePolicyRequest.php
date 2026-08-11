<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;

/**
 * PATCH /api/v1/insurance-policies/{policy} — benefit/validity corrections
 * with optimistic locking.
 */
class UpdatePolicyRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'coverageType' => ['sometimes', 'string', 'max:100'],
            'validFrom' => ['sometimes', 'date'],
            'validTo' => ['nullable', 'date'],
            'benefits' => ['sometimes', 'nullable', 'array'],
            'lockVersion' => ['required', 'integer', 'min:0'],
        ];
    }
}
