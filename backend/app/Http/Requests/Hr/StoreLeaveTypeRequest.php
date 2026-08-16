<?php

namespace App\Http\Requests\Hr;

use App\Http\Requests\ApiRequest;

/**
 * POST leave-types — create a leave type with its annual entitlement.
 */
class StoreLeaveTypeRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'paidDaysPerYear' => ['required', 'integer', 'min:0'],
            'carryoverDays' => ['required', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
