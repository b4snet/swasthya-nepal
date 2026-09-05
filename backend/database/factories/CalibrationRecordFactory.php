<?php

namespace Database\Factories;

use App\Models\CalibrationRecord;
use App\Models\Asset;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

class CalibrationRecordFactory extends Factory
{
    protected $model = CalibrationRecord::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'facility_id' => $this->faker->uuid(),
            'asset_id' => $this->faker->uuid(),
            'calibration_type' => $this->faker->randomElement(['internal', 'external', 'vendor']),
            'provider' => $this->faker->company(),
            'calibrated_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'due_at' => $this->faker->dateTimeBetween('+1 month', '+1 year'),
            'result' => $this->faker->randomElement(['pass', 'fail', 'conditional']),
            'notes' => $this->faker->sentence(),
        ];
    }
}
