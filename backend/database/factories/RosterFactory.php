<?php

namespace Database\Factories;

use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Roster>
 */
class RosterFactory extends Factory
{
    protected $model = Roster::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => fn (): string => Staff::factory()->create()->getKey(),
            'shift_template_id' => fn (array $attributes): string => ShiftTemplate::factory()->create([
                'tenant_id' => Staff::query()->findOrFail($attributes['staff_id'])->tenant_id,
                'facility_id' => Staff::query()->findOrFail($attributes['staff_id'])->facility_id,
            ])->getKey(),
            'tenant_id' => fn (array $attributes): string => Staff::query()->findOrFail($attributes['staff_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Staff::query()->findOrFail($attributes['staff_id'])->facility_id,
            'roster_date' => fn (): string => now()->addDays(1)->toDateString(),
            'status' => Roster::STATUS_SCHEDULED,
            'lock_version' => 0,
        ];
    }
}
