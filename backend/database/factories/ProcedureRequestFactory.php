<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\Facility;
use App\Models\ProcedureRequest;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureRequest>
 */
class ProcedureRequestFactory extends Factory
{
    protected $model = ProcedureRequest::class;

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
            'encounter_id' => fn (array $attributes): string => Encounter::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'patient_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->patient_id,
            'requested_by_staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'procedure_name' => 'Cholecystectomy',
            'priority' => ProcedureRequest::PRIORITY_ROUTINE,
            'status' => ProcedureRequest::STATUS_REQUESTED,
            'equipment_requirements' => ['laparoscopic tower'],
            'team_requirements' => ['surgeon', 'anesthetist'],
            'lock_version' => 0,
        ];
    }
}
