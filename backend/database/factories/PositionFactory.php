<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => fn (): string => Department::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Department::query()->findOrFail($attributes['department_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Department::query()->findOrFail($attributes['department_id'])->facility_id,
            'code' => 'POS-'.strtoupper(Str::random(8)),
            'name' => fake()->jobTitle(),
            'status' => Position::STATUS_ACTIVE,
        ];
    }
}
