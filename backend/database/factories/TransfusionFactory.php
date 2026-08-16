<?php

namespace Database\Factories;

use App\Models\Crossmatch;
use App\Models\Staff;
use App\Models\Transfusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transfusion>
 */
class TransfusionFactory extends Factory
{
    protected $model = Transfusion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $crossmatch = Crossmatch::factory()->create();
        $staff = Staff::factory()->create([
            'tenant_id' => $crossmatch->tenant_id,
            'facility_id' => $crossmatch->facility_id,
        ]);

        return [
            'tenant_id' => $crossmatch->tenant_id,
            'facility_id' => $crossmatch->facility_id,
            'blood_unit_id' => $crossmatch->blood_unit_id,
            'patient_id' => $crossmatch->patient_id,
            'crossmatch_id' => $crossmatch->getKey(),
            'started_at' => now()->toIso8601String(),
            'started_by_staff_id' => $staff->getKey(),
            'status' => Transfusion::STATUS_STARTED,
            'lock_version' => 0,
        ];
    }
}
