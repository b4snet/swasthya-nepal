<?php

namespace Database\Factories;

use App\Models\ModalityScheduleException;
use Illuminate\Database\Eloquent\Factories\Factory;

class ModalityScheduleExceptionFactory extends Factory
{
    protected $model = ModalityScheduleException::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'facility_id' => $this->faker->uuid(),
            'modality_id' => $this->faker->uuid(),
            'exception_date' => $this->faker->date(),
            'start_time' => $this->faker->optional()->time(),
            'end_time' => $this->faker->optional()->time(),
            'reason' => $this->faker->randomElement(['maintenance', 'holiday', 'extended_hours']),
            'is_blocked' => $this->faker->boolean(),
            'lock_version' => 0,
        ];
    }
}