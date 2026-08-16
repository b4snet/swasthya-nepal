<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\LabOrderItem;
use App\Models\LabResultVersion;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabResultVersion>
 */
class LabResultVersionFactory extends Factory
{
    protected $model = LabResultVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => LabOrderItem::query()->findOrFail($attributes['lab_order_item_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => LabOrderItem::query()->findOrFail($attributes['lab_order_item_id'])->facility_id,
            'lab_order_item_id' => fn (): string => LabOrderItem::factory()->create()->getKey(),
            'version_no' => 1,
            'result_value' => (string) fake()->randomFloat(1, 1, 20),
            'result_unit' => fake()->randomElement(['mg/dL', 'x10^9/L', 'mmol/L']),
            'reference_range' => fake()->randomElement(['4.0–11.0', '70–99']),
            'is_critical' => false,
            'correction_reason' => null,
            'entered_by_staff_id' => fn (array $attributes): string => self::staffIn($attributes['tenant_id'], $attributes['facility_id']),
            'entered_at' => now(),
            'verified_by_staff_id' => null,
            'verified_at' => null,
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
