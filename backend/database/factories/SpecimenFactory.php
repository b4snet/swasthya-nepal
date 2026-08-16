<?php

namespace Database\Factories;

use App\Models\LabOrder;
use App\Models\Specimen;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Specimen>
 */
class SpecimenFactory extends Factory
{
    protected $model = Specimen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => LabOrder::query()->findOrFail($attributes['lab_order_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => LabOrder::query()->findOrFail($attributes['lab_order_id'])->facility_id,
            'lab_order_id' => fn (): string => LabOrder::factory()->create()->getKey(),
            'accession_number' => 'ACC-'.strtoupper(Str::random(8)),
            'specimen_type' => fake()->randomElement(['blood', 'urine', 'swab']),
            'container' => fake()->randomElement(['lithium_heparin', 'edta', 'plain']),
            'status' => Specimen::STATUS_COLLECTED,
            'collected_by_staff_id' => null,
            'collected_at' => null,
            'lock_version' => 0,
        ];
    }
}
