<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => FacilityFactory::new(),
            'facility_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['tenant_id'])->getKey(),
            'name' => fake()->randomElement(['Main Store', 'Reception Waiting', 'Nursing Station 1', 'Procedure Room A']),
            'code' => 'loc-'.Str::lower(Str::random(5)),
            'type' => Location::TYPE_OTHER,
            'status' => Location::STATUS_ACTIVE,
        ];
    }
}
