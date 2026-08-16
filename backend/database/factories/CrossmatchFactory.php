<?php

namespace Database\Factories;

use App\Models\BloodUnit;
use App\Models\Crossmatch;
use App\Models\Encounter;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Crossmatch>
 */
class CrossmatchFactory extends Factory
{
    protected $model = Crossmatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Anchor first, then derive — closures receive the RESOLVED
            // attributes in definition order.
            'blood_unit_id' => fn (): string => BloodUnit::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => BloodUnit::query()->findOrFail($attributes['blood_unit_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => BloodUnit::query()->findOrFail($attributes['blood_unit_id'])->facility_id,
            'patient_id' => fn (array $attributes): string => Encounter::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->patient_id,
            'status' => Crossmatch::STATUS_REQUESTED,
            'requested_at' => now()->toIso8601String(),
            'requested_by_staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'lock_version' => 0,
        ];
    }
}
