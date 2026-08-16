<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/organizations/{organization}/patients/{patient}/portal
 * (PRODUCT_REQUIREMENTS §6.2, DATABASE.md §3.53).
 *
 * The identifier may be an email or a phone number; the service normalizes
 * both (email lower-cased, phone to E.164-ish digits) and enforces the
 * minimum password length — a short password must never be distinguishable
 * at the validation layer from a wrong one during login, but provisioning
 * is a staff action so the strength rule lives here as a 422.
 */
class ProvisionAccountRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'loginIdentifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'min:12'],
            'passwordConfirmation' => ['required', 'string', 'same:password'],
        ];
    }
}
