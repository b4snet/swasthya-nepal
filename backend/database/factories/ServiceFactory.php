<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => FacilityFactory::new(),
            'facility_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['tenant_id'])->getKey(),
            'name' => fake()->randomElement(['OPD Consultation', 'ECG', 'X-Ray Chest', 'Follow-up Visit']),
            'code' => 'svc-'.Str::lower(Str::random(5)),
            'service_type' => 'opd_consultation',
            'status' => Service::STATUS_ACTIVE,
        ];
    }
}
