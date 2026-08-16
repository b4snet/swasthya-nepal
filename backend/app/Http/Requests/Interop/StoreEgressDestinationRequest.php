<?php

namespace App\Http\Requests\Interop;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/interop/egress-allowlist (INTEROPERABILITY.md §11,
 * SECURITY.md §22): an approved outbound destination. A valid hostname is
 * required — the allowlist is the SSRF guard, so free-form strings are not
 * accepted.
 */
class StoreEgressDestinationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'integrationId' => ['nullable', 'uuid'],
            'host' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'purpose' => ['required', 'string', 'min:5', 'max:300'],
        ];
    }
}
