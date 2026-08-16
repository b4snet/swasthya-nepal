<?php

namespace App\Http\Requests\Interop;

use App\Http\Requests\ApiRequest;
use App\Models\OauthPartner;

/**
 * POST /api/v1/interop/partners (INTEROPERABILITY.md §11): register an
 * OAuth2 client_credentials partner. The client secret is generated
 * server-side and returned once; the webhook secret (optional) enables
 * HMAC-verified inbound webhooks from this partner.
 */
class RegisterPartnerRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'in:'.implode(',', OauthPartner::ALL_SCOPES)],
            'tokenTtlSeconds' => ['required', 'integer', 'between:60,86400'],
            'webhookUrl' => ['nullable', 'url', 'max:500'],
            'webhookSecret' => ['nullable', 'string', 'min:16', 'max:200'],
        ];
    }
}
