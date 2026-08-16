<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/portal-accounts/{portalAccount}/grants (PRODUCT
 * REQUIREMENTS §6.2, DATABASE.md §3.53).
 *
 * A consent-bound data scope (appointments / results / bills) plus a
 * purpose-limitation statement. The purpose is stored for the patient to
 * review and revoke — it is a staff-entered grant reason and may not carry
 * clinical content beyond what is needed to state the purpose; the service
 * bounds it with a length limit so it can never be a free-form dump.
 */
class GrantAccessRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dataScope' => ['required', 'string', 'in:appointments,results,bills'],
            'purpose' => ['required', 'string', 'min:5', 'max:300'],
        ];
    }
}
