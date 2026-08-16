<?php

namespace Database\Factories;

use App\Models\BloodUnit;
use App\Models\Donation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BloodUnit>
 */
class BloodUnitFactory extends Factory
{
    protected $model = BloodUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $donation = Donation::factory()->create();

        return [
            'tenant_id' => $donation->tenant_id,
            'facility_id' => $donation->facility_id,
            'donation_id' => $donation->getKey(),
            'unit_number' => 'BU-'.strtoupper(Str::random(10)),
            'component_type' => 'packed_cells',
            'blood_group' => 'O',
            'rh_factor' => 'positive',
            'collected_at' => now()->toIso8601String(),
            'expiry_at' => now()->addDays(35)->toIso8601String(),
            'tested' => false,
            'test_results' => [],
            'status' => BloodUnit::STATUS_QUARANTINED,
            'lock_version' => 0,
        ];
    }
}
