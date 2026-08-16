<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Modality;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Modality>
 */
class ModalityFactory extends Factory
{
    protected $model = Modality::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'code' => 'MOD-'.strtoupper(Str::random(6)),
            'name' => fake()->randomElement(['X-Ray Room 1', 'Ultrasound', 'CT Scanner', 'MRI']),
            'modality_type' => fake()->randomElement(['xray', 'usg', 'ct', 'mri']),
            'daily_capacity' => fake()->numberBetween(10, 40),
            'status' => Modality::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
