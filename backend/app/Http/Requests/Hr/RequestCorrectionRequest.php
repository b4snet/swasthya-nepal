<?php

namespace App\Http\Requests\Hr;

use App\Http\Requests\ApiRequest;

/**
 * POST attendance/{record}/correction — request a correction on an
 * attendance record (reason required; corrected clock times optional).
 * The record is untouched until an HR approval applies it.
 */
class RequestCorrectionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'clockInAt' => ['nullable', 'date'],
            'clockOutAt' => ['nullable', 'date', 'after:clockInAt'],
        ];
    }
}
