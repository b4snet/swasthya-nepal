<?php

namespace Database\Factories;

use App\Models\Medication;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionLine>
 */
class PrescriptionLineFactory extends Factory
{
    protected $model = PrescriptionLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Prescription::query()->findOrFail($attributes['prescription_id'])->tenant_id,
            'prescription_id' => fn (): string => Prescription::factory()->create()->getKey(),
            'medication_id' => fn (array $attributes): string => Medication::factory()->create([
                'tenant_id' => Prescription::query()->findOrFail($attributes['prescription_id'])->tenant_id,
                'facility_id' => Prescription::query()->findOrFail($attributes['prescription_id'])->encounter()->value('facility_id'),
            ])->getKey(),
            'dose' => fake()->randomElement(['1 tablet', '2 tablets', '5 ml']),
            'route' => 'oral',
            'frequency' => fake()->randomElement(['once daily', 'twice daily', 'three times daily']),
            'duration' => fake()->randomElement(['5 days', '7 days', '14 days']),
            'quantity_minor' => fake()->numberBetween(5, 60),
            'status' => PrescriptionLine::STATUS_ORDERED,
            'line_no' => 1,
        ];
    }
}
