<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\LabOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabOrder>
 */
class LabOrderFactory extends Factory
{
    protected $model = LabOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->facility_id,
            'patient_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->patient_id,
            'encounter_id' => fn (): string => Encounter::factory()->create()->getKey(),
            'ordered_by_staff_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->provider_staff_id,
            'priority' => LabOrder::PRIORITY_ROUTINE,
            'status' => LabOrder::STATUS_ORDERED,
            'clinical_indication' => null,
            'ordered_at' => now(),
            'lock_version' => 0,
        ];
    }
}
