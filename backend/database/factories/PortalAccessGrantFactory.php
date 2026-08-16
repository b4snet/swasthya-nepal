<?php

namespace Database\Factories;

use App\Models\PortalAccessGrant;
use App\Models\PortalAccount;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortalAccessGrant>
 */
class PortalAccessGrantFactory extends Factory
{
    protected $model = PortalAccessGrant::class;

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
            'data_scope' => PortalAccessGrant::SCOPE_APPOINTMENTS,
            'purpose' => 'Patient self-service view of own appointments',
            'status' => PortalAccessGrant::STATUS_GRANTED,
            'granted_at' => now()->toIso8601String(),
            'granted_by_staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'revoked_at' => null,
            'revoked_by_staff_id' => null,
            'revoked_by_patient' => false,
            'lock_version' => 0,
        ];
    }
}
