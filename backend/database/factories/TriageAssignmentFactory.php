<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Encounter;
use App\Models\Staff;
use App\Models\TriageAssignment;
use App\Models\TriageScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TriageAssignment>
 */
class TriageAssignmentFactory extends Factory
{
    protected $model = TriageAssignment::class;

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
            'triage_scale_id' => fn (array $attributes): string => TriageScale::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'level' => fn (array $attributes): int => (int) TriageScale::query()->findOrFail($attributes['triage_scale_id'])->level,
            'color' => fn (array $attributes): ?string => TriageScale::query()->findOrFail($attributes['triage_scale_id'])->color,
            'assessed_by_staff_id' => fn (array $attributes): string => self::staffIn($attributes['tenant_id'], $attributes['facility_id']),
            'assessed_at' => now(),
            'is_override' => false,
            'override_reason' => null,
            'status' => TriageAssignment::STATUS_ACTIVE,
            'lock_version' => 0,
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
