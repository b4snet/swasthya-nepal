<?php

namespace Database\Factories;

use App\Models\OauthPartner;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OauthPartner>
 */
class OauthPartnerFactory extends Factory
{
    protected $model = OauthPartner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (): string => Organization::factory()->create()->getKey(),
            'name' => 'Partner '.substr((string) Str::uuid(), 0, 8),
            'client_id' => 'partner-'.substr((string) Str::uuid(), 0, 16),
            'client_secret_hash' => hash('sha256', Str::uuid()),
            'scopes' => [OauthPartner::SCOPE_FHIR_PATIENT],
            'status' => OauthPartner::STATUS_ACTIVE,
            'token_ttl_seconds' => 3600,
            'webhook_url' => null,
            'webhook_secret_hash' => null,
            'created_by_staff_id' => null,
            'lock_version' => 0,
        ];
    }
}
