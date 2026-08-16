<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Encounter;
use App\Models\ErRegistration;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErRegistration>
 */
class ErRegistrationFactory extends Factory
{
    protected $model = ErRegistration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->facility_id,
            'patient_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->patient_id,
            'encounter_id' => fn (): string => Encounter::factory()->create(['type' => Encounter::TYPE_ER])->getKey(),
            'registered_by' => fn (array $attributes): string => self::staffIn($attributes['tenant_id'], $attributes['facility_id']),
            'registered_at' => now(),
            'presenting_complaint' => fake()->sentence(6),
            'estimated_age' => null,
            'is_unidentified' => false,
            'completed_at' => null,
            'completed_by' => null,
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
