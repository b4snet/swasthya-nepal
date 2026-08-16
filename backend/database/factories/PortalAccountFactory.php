<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\PortalAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PortalAccount>
 */
class PortalAccountFactory extends Factory
{
    protected $model = PortalAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'patient_id' => fn (array $attributes): string => Patient::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'login_identifier' => fn (): string => 'patient-'.substr((string) Str::uuid(), 0, 8).'@example.test',
            'password_hash' => bcrypt('correct-horse-battery-staple'),
            'status' => PortalAccount::STATUS_ACTIVE,
            'failed_attempts' => 0,
            'locked_until' => null,
            'mfa_enabled' => false,
            'last_login_at' => null,
            'lock_version' => 0,
        ];
    }
}
