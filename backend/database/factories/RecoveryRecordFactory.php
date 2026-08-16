<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\RecoveryRecord;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecoveryRecord>
 */
class RecoveryRecordFactory extends Factory
{
    protected $model = RecoveryRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $procedure = Procedure::factory()->create();
        $staff = Staff::factory()->create([
            'tenant_id' => $procedure->tenant_id,
            'facility_id' => $procedure->facility_id,
        ]);

        return [
            'tenant_id' => $procedure->tenant_id,
            'facility_id' => $procedure->facility_id,
            'procedure_id' => $procedure->getKey(),
            'admitted_at' => now()->toIso8601String(),
            'admitted_by_staff_id' => $staff->getKey(),
            'observations' => ['hr' => 82, 'spo2' => 97],
            'status' => RecoveryRecord::STATUS_IN_RECOVERY,
            'lock_version' => 0,
        ];
    }
}
