<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Facility;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The facility is the anchor: a fresh facility (with its own
            // org) when none is supplied; tenant and department derive from
            // it, so the composite FKs always line up.
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'department_id' => fn (array $attributes): string => Department::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'employee_code' => 'EMP-'.strtoupper(Str::random(8)),
            'full_name' => fake()->name(),
            'designation' => fake()->randomElement(['Staff Nurse', 'Consultant', 'Lab Officer', 'Pharmacist']),
            'status' => Staff::STATUS_ACTIVE,
            'settings' => [],
        ];
    }
}
