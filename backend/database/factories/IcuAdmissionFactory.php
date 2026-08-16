<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\Facility;
use App\Models\IcuAdmission;
use App\Models\IcuBed;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IcuAdmission>
 */
class IcuAdmissionFactory extends Factory
{
    protected $model = IcuAdmission::class;

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
            'icu_bed_id' => fn (array $attributes): string => IcuBed::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'source' => 'ipd',
            'acuity' => 'level_3',
            'observation_interval_minutes' => 60,
            'next_observation_due_at' => now()->addHour()->toIso8601String(),
            'status' => IcuAdmission::STATUS_ADMITTED,
            'admitted_at' => now()->toIso8601String(),
            'admitted_by_staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'lock_version' => 0,
        ];
    }
}
