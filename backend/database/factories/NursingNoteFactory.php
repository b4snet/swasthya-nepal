<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\Department;
use App\Models\NursingNote;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NursingNote>
 */
class NursingNoteFactory extends Factory
{
    protected $model = NursingNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->facility_id,
            'admission_id' => fn (): string => Admission::factory()->create()->getKey(),
            'author_staff_id' => fn (array $attributes): string => self::staffIn($attributes['tenant_id'], $attributes['facility_id']),
            'content' => [
                'observation' => fake()->sentence(8),
                'intervention' => fake()->sentence(6),
                'response' => fake()->sentence(6),
            ],
            'status' => NursingNote::STATUS_DRAFT,
            'signed_at' => null,
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
