<?php

namespace App\Http\Requests\Bed;

use App\Http\Requests\ApiRequest;
use App\Support\BedStatus;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/beds/{bed}.
 *
 * Status is a state machine (DATABASE.md §0.5): the controller validates
 * the transition via BedStatus and audits every change. lockVersion is the
 * optimistic-locking guard (DATABASE.md §0.7) — a stale value is rejected
 * with 409 LOCK_CONFLICT, never silently overwritten.
 */
class UpdateBedRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(BedStatus::VALID)],
            'lockVersion' => ['required', 'integer', 'min:0'],
        ];
    }
}
