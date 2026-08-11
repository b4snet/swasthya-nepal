<?php

namespace App\Http\Requests\Encounter;

use App\Http\Requests\ApiRequest;

/**
 * POST encounters/{encounter}/prescriptions — header plus lines in one
 * atomic call. Line fields are validated per entry; at least one line.
 */
class StorePrescriptionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.medicationId' => ['required', 'uuid'],
            'lines.*.dose' => ['required', 'string', 'max:100'],
            'lines.*.route' => ['required', 'string', 'max:50'],
            'lines.*.frequency' => ['required', 'string', 'max:100'],
            'lines.*.duration' => ['nullable', 'string', 'max:100'],
            'lines.*.quantityMinor' => ['nullable', 'integer', 'min:1'],
            'lines.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
