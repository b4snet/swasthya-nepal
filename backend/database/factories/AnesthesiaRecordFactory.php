<?php

namespace Database\Factories;

use App\Models\AnesthesiaRecord;
use App\Models\Procedure;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnesthesiaRecord>
 */
class AnesthesiaRecordFactory extends Factory
{
    protected $model = AnesthesiaRecord::class;

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
            'anesthetist_staff_id' => $staff->getKey(),
            'anesthesia_type' => 'general',
            'started_at' => now()->toIso8601String(),
            'status' => AnesthesiaRecord::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
