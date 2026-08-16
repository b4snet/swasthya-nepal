<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\ApiRequest;

/**
 * POST specimens/{specimen}/reject — reject a specimen (hemolyzed, wrong
 * tube, insufficient quantity, …). The reason is REQUIRED (CHECK
 * chk_specimens_reject) and is a clinical fact — it is stored on the
 * specimen but never written to audit payloads.
 */
class RejectSpecimenRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
