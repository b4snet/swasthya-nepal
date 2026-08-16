<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Encounter;
use App\Models\ErEvent;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErEvent>
 */
class ErEventFactory extends Factory
{
    protected $model = ErEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->facility_id,
            'encounter_id' => fn (): string => Encounter::factory()->create(['type' => Encounter::TYPE_ER])->getKey(),
            'patient_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->patient_id,
            'event_type' => ErEvent::TYPE_ARRIVED,
            'notes' => null,
            'occurred_at' => now(),
            'actor_staff_id' => fn (array $attributes): string => self::staffIn($attributes['tenant_id'], $attributes['facility_id']),
        ];
    }

    private static function staffIn(string $tenantId, string $facilityId): string
    {
        $department = Department::factory()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
        ]);

        return Staff::factory()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_id' => $department->getKey(),
            'status' => Staff::STATUS_ACTIVE,
        ])->getKey();
    }
}
