<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\LabTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabTest>
 */
class LabTestFactory extends Factory
{
    protected $model = LabTest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'code' => strtoupper(fake()->unique()->bothify('LAB-####')),
            'name' => fake()->randomElement(['Complete Blood Count', 'Blood Glucose', 'Urine Analysis', 'Chest X-Ray', 'Lipid Profile', 'Liver Function Test']),
            'category' => fake()->randomElement(['laboratory', 'hematology', 'biochemistry', 'microbiology', 'radiology']),
            'sample_type' => fake()->randomElement(['blood', 'urine', 'swab', 'stool']),
            'unit' => fake()->randomElement(['mg/dL', 'g/dL', 'x10^9/L', 'mmol/L', null]),
            'reference_range' => fake()->randomElement(['4.0–11.0', '70–99', '13.5–17.5', null]),
            'method' => null,
            'status' => LabTest::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
