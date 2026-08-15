<?php

namespace Database\Factories;

use App\Models\CriticalValueEvent;
use App\Models\LabOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CriticalValueEvent>
 */
class CriticalValueEventFactory extends Factory
{
    protected $model = CriticalValueEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => LabOrderItem::query()->findOrFail($attributes['lab_order_item_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => LabOrderItem::query()->findOrFail($attributes['lab_order_item_id'])->facility_id,
            'lab_order_item_id' => fn (): string => LabOrderItem::factory()->create()->getKey(),
            'patient_id' => fn (array $attributes): string => LabOrderItem::query()->findOrFail($attributes['lab_order_item_id'])->order->patient_id,
            'encounter_id' => fn (array $attributes): string => LabOrderItem::query()->findOrFail($attributes['lab_order_item_id'])->order->encounter_id,
            'target_staff_id' => fn (array $attributes): string => LabOrderItem::query()->findOrFail($attributes['lab_order_item_id'])->order->ordered_by_staff_id,
            'status' => CriticalValueEvent::STATUS_TRIGGERED,
            'detected_by_staff_id' => fn (array $attributes): string => LabOrderItem::query()->findOrFail($attributes['lab_order_item_id'])->entered_by_staff_id ?? throw new \RuntimeException('Critical event factory needs an entered_by_staff_id on the item'),
            'detected_at' => now(),
            'lock_version' => 0,
        ];
    }
}
