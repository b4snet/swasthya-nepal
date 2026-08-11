<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => FacilityFactory::new(),
            'facility_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['tenant_id'])->getKey(),
            'name' => fake()->randomElement(['OPD', 'Surgery', 'Pharmacy', 'Radiology', 'Laboratory']),
            'code' => 'dept-'.Str::lower(Str::random(5)),
            'status' => Department::STATUS_ACTIVE,
        ];
    }
}
