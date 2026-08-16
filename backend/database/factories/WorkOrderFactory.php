<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => fn (): string => Asset::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Asset::query()->findOrFail($attributes['asset_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Asset::query()->findOrFail($attributes['asset_id'])->facility_id,
            'work_order_number' => 'WO-'.strtoupper(Str::random(10)),
            'status' => WorkOrder::STATUS_OPEN,
            'opened_at' => fn (): string => now()->toIso8601String(),
            'lock_version' => 0,
        ];
    }
}
