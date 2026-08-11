<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\FacilitySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacilitySetting>
 */
class FacilitySettingFactory extends Factory
{
    protected $model = FacilitySetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => FacilityFactory::new(),
            'facility_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['tenant_id'])->getKey(),
            'key' => fake()->unique()->bothify('setting.##'),
            'value' => ['enabled' => true],
            'version' => 1,
        ];
    }
}
