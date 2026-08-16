<?php

namespace App\Http\Requests\IpdNursing;

use App\Http\Requests\ApiRequest;
use App\Models\MarEntry;
use Illuminate\Validation\Rule;

/**
 * POST mar-entries/{marEntry}/administer — scheduled → given | refused |
 * missed | held (CLINICAL_SAFETY.md §190): 'given' REQUIRES identity
 * re-confirmation (name + MRN on-screen); refused/missed/held require a
 * captured reason.
 */
class AdministerMarEntryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                MarEntry::STATUS_GIVEN,
                MarEntry::STATUS_REFUSED,
                MarEntry::STATUS_MISSED,
                MarEntry::STATUS_HELD,
            ])],
            'reason' => [
                'required_if:status,refused',
                'required_if:status,missed',
                'required_if:status,held',
                'string',
                'max:1000',
            ],
            'identityConfirmed' => [
                'required',
                'boolean',
                Rule::when($this->input('status') === MarEntry::STATUS_GIVEN, 'accepted'),
            ],
        ];
    }
}
