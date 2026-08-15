<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\ApiRequest;

/**
 * POST lab-orders/{labOrder}/results — result entry by lab staff. Entry is
 * NOT verification: lab:result_entry holders enter, lab:verify holders
 * verify, and a staff member can never verify their own entry.
 */
class EnterLabResultsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1', 'max:50'],
            'results.*.itemId' => ['required', 'uuid', 'distinct'],
            'results.*.resultValue' => ['required', 'string', 'max:1000'],
            'results.*.resultUnit' => ['nullable', 'string', 'max:20'],
        ];
    }
}
