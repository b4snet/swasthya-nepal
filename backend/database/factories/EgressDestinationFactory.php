<?php

namespace Database\Factories;

use App\Models\EgressDestination;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EgressDestination>
 */
class EgressDestinationFactory extends Factory
{
    protected $model = EgressDestination::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (): string => Organization::factory()->create()->getKey(),
            'integration_id' => null,
            'host' => 'api.partner.example.test',
            'port' => 443,
            'purpose' => 'Partner API',
            'is_active' => true,
            'created_by_staff_id' => null,
        ];
    }
}
