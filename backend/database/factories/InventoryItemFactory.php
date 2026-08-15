<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Medication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Medication::query()->findOrFail($attributes['medication_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Medication::query()->findOrFail($attributes['medication_id'])->facility_id,
            'medication_id' => fn (): string => Medication::factory()->create()->getKey(),
            'quantity_on_hand' => 100,
            'reorder_level' => 10,
            'lock_version' => 0,
        ];
    }
}
