<?php

namespace Database\Factories;

use App\Models\AssetDisposal;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetDisposalFactory extends Factory
{
    protected $model = AssetDisposal::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'facility_id' => $this->faker->uuid(),
            'asset_id' => $this->faker->uuid(),
            'disposal_type' => $this->faker->randomElement(['donated', 'recycled', 'destroyed', 'sold', 'other']),
            'reason' => $this->faker->sentence(),
            'authorized_by_staff_id' => $this->faker->uuid(),
            'disposed_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
