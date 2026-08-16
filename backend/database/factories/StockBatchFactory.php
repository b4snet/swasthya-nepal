<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\StockBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockBatch>
 */
class StockBatchFactory extends Factory
{
    protected $model = StockBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => InventoryItem::query()->findOrFail($attributes['inventory_item_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => InventoryItem::query()->findOrFail($attributes['inventory_item_id'])->facility_id,
            'inventory_item_id' => fn (): string => InventoryItem::factory()->create()->getKey(),
            'medication_id' => fn (array $attributes): string => InventoryItem::query()->findOrFail($attributes['inventory_item_id'])->medication_id,
            'batch_number' => 'B-'.strtoupper(Str::random(8)),
            'expiry_date' => fn (): string => now()->addMonths(6)->toDateString(),
            'quantity_received' => 100,
            'quantity_remaining' => 100,
            'status' => StockBatch::STATUS_AVAILABLE,
            'controlled_dispense_requires_dual' => false,
            'lock_version' => 0,
        ];
    }
}
