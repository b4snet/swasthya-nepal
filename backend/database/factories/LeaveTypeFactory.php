<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'code' => 'LV-'.strtoupper(Str::random(8)),
            'name' => fake()->randomElement(['Annual Leave', 'Sick Leave', 'Casual Leave']),
            'paid_days_per_year' => 30,
            'carryover_days' => 5,
            'status' => LeaveType::STATUS_ACTIVE,
        ];
    }
}
