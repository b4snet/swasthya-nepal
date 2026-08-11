<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Medication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medication>
 */
class MedicationFactory extends Factory
{
    protected $model = Medication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'code' => strtoupper(fake()->unique()->bothify('MED-####')),
            'generic_name' => fake()->randomElement(['Paracetamol', 'Amoxicillin', 'Metformin', 'Amlodipine', 'Omeprazole']),
            'brand_name' => fake()->word(),
            'strength' => fake()->randomElement(['500 mg', '250 mg', '10 mg', '20 mg']),
            'form' => 'tablet',
            'unit' => 'tablet',
            'price_minor' => fake()->numberBetween(100, 50000),
            'currency' => 'NPR',
            'is_controlled' => false,
            'status' => Medication::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
