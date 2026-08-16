<?php

namespace Database\Factories;

use App\Models\PortalAccount;
use App\Models\PortalSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortalSession>
 */
class PortalSessionFactory extends Factory
{
    protected $model = PortalSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portal_account_id' => fn (): string => PortalAccount::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => PortalAccount::query()->findOrFail($attributes['portal_account_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => PortalAccount::query()->findOrFail($attributes['portal_account_id'])->facility_id,
            'patient_id' => fn (array $attributes): string => PortalAccount::query()->findOrFail($attributes['portal_account_id'])->patient_id,
            'token_id' => random_int(1000, 999999),
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
            'expires_at' => now()->addMinutes(60),
            'revoked_at' => null,
            'revoked_by' => null,
        ];
    }
}
