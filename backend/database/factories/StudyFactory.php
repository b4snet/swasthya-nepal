<?php

namespace Database\Factories;

use App\Models\LabOrder;
use App\Models\Study;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Study>
 */
class StudyFactory extends Factory
{
    protected $model = Study::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => LabOrder::query()->findOrFail($attributes['lab_order_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => LabOrder::query()->findOrFail($attributes['lab_order_id'])->facility_id,
            'lab_order_id' => fn (): string => LabOrder::factory()->create()->getKey(),
            'modality_id' => null,
            'status' => Study::STATUS_ORDERED,
            'ordered_at' => fn (): string => now()->toDateTimeString(),
            'scheduled_at' => null,
            'performed_at' => null,
            'performed_by_staff_id' => null,
            'cancel_reason' => null,
            'preparation_instructions' => null,
            'lock_version' => 0,
        ];
    }
}
