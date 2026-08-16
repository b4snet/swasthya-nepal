<?php

namespace App\Http\Requests\Ot;

use App\Http\Requests\ApiRequest;

/**
 * POST procedures/{procedure}/team — log a surgical team member
 * (PRODUCT_REQUIREMENTS §6.10).
 */
class AddTeamMemberRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staffId' => ['required', 'string', 'uuid'],
            'role' => ['required', 'in:surgeon,assistant,anesthetist,nurse,perfusionist,other'],
            'timeIn' => ['nullable', 'date'],
        ];
    }
}
