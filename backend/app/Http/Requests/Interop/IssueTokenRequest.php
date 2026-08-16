<?php

namespace App\Http\Requests\Interop;

use App\Http\Requests\ApiRequest;
use App\Models\OauthPartner;

/**
 * POST /api/v1/interop/oauth/token (public partner surface) — OAuth2
 * client_credentials. Validation only: credential verification and scope
 * intersection live in PartnerOauthService (an unknown client and a wrong
 * secret must be indistinguishable — no client/tenant enumeration).
 */
class IssueTokenRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clientId' => ['required', 'string', 'max:64'],
            'clientSecret' => ['required', 'string', 'max:200'],
            'scope' => ['required', 'array', 'min:1'],
            'scope.*' => ['required', 'string', 'in:'.implode(',', OauthPartner::ALL_SCOPES)],
        ];
    }
}
