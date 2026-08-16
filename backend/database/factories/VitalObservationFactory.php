<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\Department;
use App\Models\Staff;
use App\Models\VitalObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VitalObservation>
 */
class VitalObservationFactory extends Factory
{
    protected $model = VitalObservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->facility_id,
            'admission_id' => fn (): string => Admission::factory()->create()->getKey(),
            'encounter_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->encounter_id,
            'patient_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->patient_id,
            'type' => VitalObservation::TYPE_BP,
            'value' => ['systolic' => 120, 'diastolic' => 80],
            'measured_at' => now(),
            'measured_by' => fn (array $attributes): string => self::staffIn($attributes['tenant_id'], $attributes['facility_id']),
            'is_abnormal' => null,
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
