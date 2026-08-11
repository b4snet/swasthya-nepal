<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    protected $model = Prescription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->tenant_id,
            'patient_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->patient_id,
            'encounter_id' => fn (): string => Encounter::factory()->create()->getKey(),
            'prescriber_staff_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->provider_staff_id,
            'status' => Prescription::STATUS_DRAFTED,
            'lock_version' => 0,
        ];
    }
}
