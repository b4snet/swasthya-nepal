<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\TriageScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TriageScale>
 */
class TriageScaleFactory extends Factory
{
    protected $model = TriageScale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'code' => 'L'.fake()->numberBetween(1, 5),
            'name' => fake()->randomElement(['Resuscitation', 'Emergent', 'Urgent', 'Semi-urgent', 'Non-urgent']),
            'level' => fake()->numberBetween(1, 5),
            'color' => fake()->randomElement(['red', 'orange', 'yellow', 'green', 'blue']),
            'reassessment_minutes' => fake()->randomElement([5, 10, 30, 60, 120]),
            'is_default' => false,
            'status' => TriageScale::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
