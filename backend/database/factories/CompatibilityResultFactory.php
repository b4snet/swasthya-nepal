<?php

namespace Database\Factories;

use App\Models\CompatibilityResult;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompatibilityResult>
 */
class CompatibilityResultFactory extends Factory
{
    protected $model = CompatibilityResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Anchor first, then derive — closures receive the RESOLVED
            // attributes in definition order.
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'patient_id' => fn (array $attributes): string => Encounter::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->patient_id,
            'patient_blood_group' => 'O',
            'patient_rh_factor' => 'positive',
            'abo_rh_compatible' => true,
            'antibody_screen' => 'negative',
            'result' => CompatibilityResult::RESULT_COMPATIBLE,
            'checked_at' => now()->toIso8601String(),
            'checked_by_staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
        ];
    }
}
