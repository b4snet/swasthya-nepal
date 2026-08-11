<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => FacilityFactory::new(),
            'facility_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['tenant_id'])->getKey(),
            'mrn' => 'MRN-'.strtoupper(Str::random(8)),
            'full_name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-1 year')->format('Y-m-d'),
            'sex' => fake()->randomElement(['male', 'female']),
            'blood_group' => fake()->randomElement(['A+', 'B+', 'O+', 'AB-']),
            'status' => Patient::STATUS_ACTIVE,
            'consent_summary' => [],
            'lock_version' => 0,
        ];
    }
}
