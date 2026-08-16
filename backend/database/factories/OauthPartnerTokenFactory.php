<?php

namespace Database\Factories;

use App\Models\OauthPartner;
use App\Models\OauthPartnerToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OauthPartnerToken>
 */
class OauthPartnerTokenFactory extends Factory
{
    protected $model = OauthPartnerToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => OauthPartner::query()
                ->findOrFail($attributes['oauth_partner_id'])->tenant_id,
            'oauth_partner_id' => fn (): string => OauthPartner::factory()->create()->getKey(),
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'scopes' => ['fhir:Patient'],
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'last_used_at' => null,
        ];
    }
}
