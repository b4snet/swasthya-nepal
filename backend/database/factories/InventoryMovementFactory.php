<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => InventoryItem::query()->findOrFail($attributes['inventory_item_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => InventoryItem::query()->findOrFail($attributes['inventory_item_id'])->facility_id,
            'inventory_item_id' => fn (): string => InventoryItem::factory()->create()->getKey(),
            'movement_type' => InventoryMovement::TYPE_RECEIPT,
            'quantity_delta' => 100,
            'reason' => null,
            'prescription_line_id' => null,
            'occurred_at' => now(),
        ];
    }
}
