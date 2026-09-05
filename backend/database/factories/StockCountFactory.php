<?php

namespace Database\Factories;

use App\Models\StockCount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCount>
 */
class StockCountFactory extends Factory
{
    protected $model = StockCount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $expected = fake()->numberBetween(10, 100);
        $counted = $expected + fake()->numberBetween(-5, 5);

        return [
            'tenant_id' => null,
            'facility_id' => null,
            'medication_id' => null,
            'inventory_item_id' => null,
            'expected_quantity' => $expected,
            'counted_quantity' => $counted,
            'variance' => $counted - $expected,
            'reason' => fake()->sentence(),
            'counted_by_staff_id' => null,
            'reviewed_by_staff_id' => null,
            'status' => StockCount::STATUS_COUNTED,
            'counted_at' => now(),
            'reviewed_at' => null,
            'created_by' => null,
        ];
    }
}
