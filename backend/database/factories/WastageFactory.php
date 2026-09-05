<?php

namespace Database\Factories;

use App\Models\Wastage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wastage>
 */
class WastageFactory extends Factory
{
    protected $model = Wastage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'facility_id' => null,
            'medication_id' => null,
            'inventory_item_id' => null,
            'stock_batch_id' => null,
            'batch_number' => fake()->numerify('BATCH-#####'),
            'batch_expires_at' => now()->addDays(30),
            'quantity_minor' => fake()->numberBetween(1, 50),
            'reason_code' => Wastage::REASON_EXPIRED,
            'reason_note' => null,
            'wasted_by_staff_id' => null,
            'witness_staff_id' => null,
            'wasted_at' => now(),
            'created_by' => null,
        ];
    }
}
