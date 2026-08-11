<?php

namespace Database\Factories;

use App\Models\Diagnosis;
use App\Models\Encounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Diagnosis>
 */
class DiagnosisFactory extends Factory
{
    protected $model = Diagnosis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->tenant_id,
            'encounter_id' => fn (): string => Encounter::factory()->create()->getKey(),
            'code' => fake()->randomElement(['J06.9', 'I10', 'E11.9', 'K29.7', 'N39.0']),
            'coding_system' => 'icd10',
            'description' => fake()->sentence(6),
            'diagnosis_type' => Diagnosis::TYPE_PROVISIONAL,
            'is_primary' => false,
            'status' => Diagnosis::STATUS_ACTIVE,
        ];
    }
}
