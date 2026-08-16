<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureRequest;
use App\Models\Theatre;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Procedure>
 */
class ProcedureFactory extends Factory
{
    protected $model = Procedure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $request = ProcedureRequest::factory()->create();
        $theatre = Theatre::factory()->create([
            'tenant_id' => $request->tenant_id,
            'facility_id' => $request->facility_id,
        ]);

        return [
            'tenant_id' => $request->tenant_id,
            'facility_id' => $request->facility_id,
            'procedure_request_id' => $request->getKey(),
            'patient_id' => $request->patient_id,
            'encounter_id' => $request->encounter_id,
            'theatre_id' => $theatre->getKey(),
            'status' => Procedure::STATUS_SCHEDULED,
            'lock_version' => 0,
        ];
    }
}
