<?php

namespace Database\Factories;

use App\Models\InsurancePolicy;
use App\Models\Patient;
use App\Models\Payer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InsurancePolicy>
 */
class InsurancePolicyFactory extends Factory
{
    protected $model = InsurancePolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'patient_id' => PatientFactory::new(),
            'payer_id' => fn (array $attributes): string => Payer::factory()->create(['tenant_id' => $attributes['tenant_id']])->getKey(),
            'policy_number' => strtoupper(Str::random(10)),
            'coverage_type' => 'general',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'benefits' => [],
            'status' => InsurancePolicy::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
