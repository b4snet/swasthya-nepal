<?php

namespace Database\Factories;

use App\Models\StaffCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffCredentialFactory extends Factory
{
    protected $model = StaffCredential::class;

    public function definition(): array
    {
        $issueDate = $this->faker->dateTimeBetween('-2 years', '-1 month');
        $expiryDate = $this->faker->dateTimeBetween('+1 month', '+2 years');

        return [
            'tenant_id' => $this->faker->uuid(),
            'facility_id' => $this->faker->uuid(),
            'staff_id' => $this->faker->uuid(),
            'credential_type' => $this->faker->randomElement(['medical_license', 'nursing_license', 'registration', 'certification']),
            'credential_code' => strtoupper($this->faker->lexify('????').'-'.$this->faker->numerify('######')),
            'title' => $this->faker->words(3, true),
            'issuing_authority' => $this->faker->company(),
            'issue_date' => $issueDate,
            'expiry_date' => $expiryDate,
            'status' => StaffCredential::STATUS_ACTIVE,
        ];
    }
}
