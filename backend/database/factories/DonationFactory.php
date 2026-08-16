<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\Donor;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    protected $model = Donation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $donor = Donor::factory()->create();
        $staff = Staff::factory()->create([
            'tenant_id' => $donor->tenant_id,
            'facility_id' => $donor->facility_id,
        ]);

        return [
            'tenant_id' => $donor->tenant_id,
            'facility_id' => $donor->facility_id,
            'donor_id' => $donor->getKey(),
            'donated_at' => now()->toIso8601String(),
            'phlebotomist_staff_id' => $staff->getKey(),
            'volume_ml' => 450,
            'screening_result' => 'eligible',
            'status' => Donation::STATUS_COLLECTED,
            'lock_version' => 0,
        ];
    }
}
