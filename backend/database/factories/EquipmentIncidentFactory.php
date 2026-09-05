<?php

namespace Database\Factories;

use App\Models\EquipmentIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

class EquipmentIncidentFactory extends Factory
{
    protected $model = EquipmentIncident::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'facility_id' => $this->faker->uuid(),
            'asset_id' => $this->faker->uuid(),
            'incident_type' => $this->faker->randomElement(['malfunction', 'safety', 'near_miss', 'other']),
            'description' => $this->faker->sentence(),
            'reported_by_staff_id' => $this->faker->uuid(),
            'occurred_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'severity' => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'status' => 'open',
        ];
    }
}
