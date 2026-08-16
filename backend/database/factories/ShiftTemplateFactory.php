<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\ShiftTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShiftTemplate>
 */
class ShiftTemplateFactory extends Factory
{
    protected $model = ShiftTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'code' => 'SHIFT-'.strtoupper(Str::random(8)),
            'name' => fake()->randomElement(['Day', 'Night', 'Evening', 'Morning']),
            'shift_type' => ShiftTemplate::TYPE_DAY,
            'starts_at' => '08:00',
            'ends_at' => '16:00',
            'working_minutes' => 480,
            'status' => ShiftTemplate::STATUS_ACTIVE,
        ];
    }
}
