<?php

namespace Database\Factories;

use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabOrderItem>
 */
class LabOrderItemFactory extends Factory
{
    protected $model = LabOrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => LabOrder::query()->findOrFail($attributes['lab_order_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => LabOrder::query()->findOrFail($attributes['lab_order_id'])->facility_id,
            'lab_order_id' => fn (): string => LabOrder::factory()->create()->getKey(),
            'lab_test_id' => fn (array $attributes): string => LabTest::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'reference_range' => null,
        ];
    }
}
