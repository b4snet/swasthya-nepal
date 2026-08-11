<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\ScheduleTemplate;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleTemplate>
 */
class ScheduleTemplateFactory extends Factory
{
    protected $model = ScheduleTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'starts_at' => '09:00',
            'ends_at' => '17:00',
            'slot_minutes' => 15,
            'capacity' => 1,
            'valid_from' => today()->toDateString(),
            'valid_to' => null,
            'status' => ScheduleTemplate::STATUS_ACTIVE,
        ];
    }
}
