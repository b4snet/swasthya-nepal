<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ward>
 */
class WardFactory extends Factory
{
    protected $model = Ward::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => FacilityFactory::new(),
            'facility_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['tenant_id'])->getKey(),
            'name' => fake()->randomElement(['General Ward', 'Surgery Ward', 'Pediatric Ward', 'ICU']),
            'code' => 'ward-'.Str::lower(Str::random(5)),
            'ward_type' => 'general',
            'status' => Ward::STATUS_ACTIVE,
            'settings' => [],
        ];
    }
}
