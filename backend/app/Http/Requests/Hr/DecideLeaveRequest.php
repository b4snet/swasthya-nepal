<?php

namespace App\Http\Requests\Hr;

use App\Http\Requests\ApiRequest;

/**
 * POST leave-requests/{request}/approve|reject — decide a pending request.
 */
class DecideLeaveRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
