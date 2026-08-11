<?php

namespace Database\Factories;

use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => OrganizationFactory::new(),
            'name' => fake()->city().' Hospital',
            'code' => 'fac-'.Str::lower(Str::random(5)),
            'status' => Facility::STATUS_ACTIVE,
            'timezone' => 'Asia/Kathmandu',
            'address' => [],
            'settings' => [],
        ];
    }
}
