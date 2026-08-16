<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\MaintenanceSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceSchedule>
 */
class MaintenanceScheduleFactory extends Factory
{
    protected $model = MaintenanceSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => fn (): string => Asset::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Asset::query()->findOrFail($attributes['asset_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Asset::query()->findOrFail($attributes['asset_id'])->facility_id,
            'schedule_type' => MaintenanceSchedule::TYPE_PREVENTIVE,
            'frequency_days' => 90,
            'next_due_date' => fn (): string => now()->addDays(90)->toDateString(),
            'status' => MaintenanceSchedule::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
