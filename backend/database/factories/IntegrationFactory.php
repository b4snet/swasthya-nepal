<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (): string => Organization::factory()->create()->getKey(),
            'type' => Integration::TYPE_FHIR,
            'provider' => 'swasthya',
            'config_encrypted' => null,
            'status' => Integration::STATUS_ACTIVE,
            'owner_staff_id' => null,
            'purpose' => 'Readiness projection layer',
            'contract_version' => '1.0.0',
            'standards_version' => 'FHIR R4.0.1',
            'mapping_version' => '1',
            'kill_switched' => false,
            'last_checked_at' => null,
            'health' => null,
            'lock_version' => 0,
        ];
    }
}
