<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    protected $model = FollowUp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->facility_id,
            'patient_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->patient_id,
            'encounter_id' => fn (): string => Encounter::factory()->create()->getKey(),
            'provider_staff_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->provider_staff_id,
            'follow_up_type' => FollowUp::TYPE_RETURN_VISIT,
            'planned_at' => now()->addDays(7),
            'reason' => null,
            'booked_appointment_id' => null,
            'status' => FollowUp::STATUS_PLANNED,
            'cancel_reason' => null,
            'lock_version' => 0,
        ];
    }
}
