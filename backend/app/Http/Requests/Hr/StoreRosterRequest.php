<?php

namespace App\Http\Requests\Hr;

use App\Http\Requests\ApiRequest;

/**
 * POST rosters — assign a staff member to a shift on a date (conflict
 * detection: overlaps and rest rules enforced in HrAssetsService).
 */
class StoreRosterRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staffId' => ['required', 'string', 'uuid'],
            'shiftTemplateId' => ['required', 'string', 'uuid'],
            'rosterDate' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
