<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\SurgicalEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurgicalEvent>
 */
class SurgicalEventFactory extends Factory
{
    protected $model = SurgicalEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $procedure = Procedure::factory()->create();

        return [
            'tenant_id' => $procedure->tenant_id,
            'facility_id' => $procedure->facility_id,
            'procedure_id' => $procedure->getKey(),
            'event_type' => SurgicalEvent::EVENT_TIME_OUT,
            'occurred_at' => now()->toIso8601String(),
            'staff_id' => null,
        ];
    }
}
