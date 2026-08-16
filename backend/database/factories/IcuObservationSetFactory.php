<?php

namespace Database\Factories;

use App\Models\IcuAdmission;
use App\Models\IcuObservationSet;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IcuObservationSet>
 */
class IcuObservationSetFactory extends Factory
{
    protected $model = IcuObservationSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Anchor first, then derive — closures receive the RESOLVED
            // attributes in definition order.
            'icu_admission_id' => fn (): string => IcuAdmission::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => IcuAdmission::query()->findOrFail($attributes['icu_admission_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => IcuAdmission::query()->findOrFail($attributes['icu_admission_id'])->facility_id,
            'observed_at' => now()->toIso8601String(),
            'observed_by_staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'values' => ['hr' => 72, 'spo2' => 98, 'sbp' => 118],
        ];
    }
}
