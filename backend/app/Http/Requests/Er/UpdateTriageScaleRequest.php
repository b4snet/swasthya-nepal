<?php

namespace App\Http\Requests\Er;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH er/triage-scales/{triageScale} — update an acuity level (CAS on
 * lockVersion). The code/level snapshot on existing triage assignments is
 * never rewritten.
 */
class UpdateTriageScaleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:255'],
            'level' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'color' => ['sometimes', 'string', 'max:20'],
            'reassessmentMinutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'isDefault' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'lockVersion' => ['required', 'integer', 'min:0'],
        ];
    }
}
