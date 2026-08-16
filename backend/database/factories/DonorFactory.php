<?php

namespace Database\Factories;

use App\Models\Donor;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Donor>
 */
class DonorFactory extends Factory
{
    protected $model = Donor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facility = Facility::factory()->create();

        return [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->getKey(),
            'donor_number' => 'DN-'.strtoupper(Str::random(8)),
            'full_name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'sex' => fake()->randomElement(['male', 'female']),
            'blood_group' => fake()->randomElement(['A', 'B', 'AB', 'O']),
            'rh_factor' => fake()->randomElement(['positive', 'negative']),
            'status' => Donor::STATUS_ACTIVE,
            'screening' => ['weight_kg' => 60, 'last_donation_days' => 120],
            'lock_version' => 0,
        ];
    }
}
